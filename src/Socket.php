<?php

namespace Cydrickn\SocketIO;

use Cydrickn\SocketIO\Enum\Type;
use Cydrickn\SocketIO\Message\Response;
use Cydrickn\SocketIO\Message\ResponseFactory;
use Cydrickn\SocketIO\Router\Router;
use Cydrickn\SocketIO\Room\RoomsInterface;
use Cydrickn\SocketIO\Session\Session;
use Psr\Http\Message\ServerRequestInterface;
use Swoole\Http\Request;
use Swoole\WebSocket\Frame;
use Swoole\WebSocket\Server as WebsocketServer;

class Socket
{
    public const NO_SENDER = 0;
    public const SYSTEM_FD = 0;

    public const SYSTEM_EVENT = [
        'Start',
        'WorkerStart',
        'Request',
        'Open',
        'Message',
        'Close',
    ];

    protected WebsocketServer $server;

    protected Router $router;

    protected int $fd = Socket::SYSTEM_FD;

    public string $sid = '';

    public int $pingInterval = 20000;

    public int $pingTimeout = 25000;

    protected ResponseFactory $responseFactory;

    protected ?Request $request = null;

    private array $attributes = [];

    public function __get(string $name)
    {
        return $this->attributes[$name] ?? null;
    }

    public function __set(string $name, mixed $data)
    {
        $this->attributes[$name] = $data;
    }

    public function __construct(WebsocketServer $server, Router $router, RoomsInterface $rooms, ResponseFactory $responseFactory)
    {
        $this->server = $server;
        $this->router = $router;
        $this->responseFactory = $responseFactory;
        $this->rooms = $rooms;
    }

    public function isEstablished(int $fd): bool
    {
        return $this->server->isEstablished($fd);
    }

    public function sendTo(int $receiver, Frame|string $data, int $opcode = WEBSOCKET_OPCODE_TEXT, bool $finish = true, callable|null $callback = null): bool
    {
        if (!$this->isEstablished($receiver)) {
            return  false;
        }

        if (is_callable($callback)) {
            $callback($receiver, $data, $opcode, $finish);
        }

        return $this->server->push($receiver, $data, $opcode, $finish);
    }

    public function sendToAll(Frame|string $data, int $opcode = WEBSOCKET_OPCODE_TEXT, int $pageSize = 50, callable|null $callback = null): int
    {
        return $this->sendToSome($data, [], [], $opcode, $pageSize, $callback);
    }

    public function sendToSome(Frame|string $data, array $receivers = [], array $excludes = [], int $opcode = WEBSOCKET_OPCODE_TEXT, int $pageSize = 50, callable|null $callback = null): int
    {
        $count = 0;

        if (!empty($receivers) && !empty($excludes)) {
            $receivers = array_diff($receivers, $excludes);
            $excludes = [];
        }

        if (!empty($receivers)) {
            foreach ($receivers as $receiver) {
                $ok = $this->sendTo($receiver, $data, $opcode, true, $callback);
                $count += $ok ? 1 : 0;
            }

            return $count;
        }

        $this->eachConnection(function ($fd) use ($data,$opcode, $excludes, $callback, &$count) {
            if (in_array($fd, $excludes)) {
                return;
            }

            $ok = $this->sendTo($fd, $data, $opcode, true, $callback);
            $count += $ok ? 1 : 0;
        }, $pageSize);

        return $count;
    }

    public function send(Frame|string $data, array $receivers = [], array $excludes = [], int $opcode = WEBSOCKET_OPCODE_TEXT, int $pageSize = 50, callable|null $callback = null): int
    {
        if (count($receivers) === 1) {
            $ok = $this->sendTo($receivers[0], $data, $opcode, true, $callback);

            return $ok ? 1 : 0;
        }

        if (empty($receivers) && empty($excludes)) {
            return $this->sendToAll($data, $opcode, $pageSize, $callback);
        }

        return $this->sendToSome($data, $receivers, $excludes, $opcode, $pageSize, $callback);
    }

    public function eachConnection(callable $handler, int $pageSize = 50): int
    {
        $count = 0;
        $startFd = 0;

        while (true) {
            $list = $this->server->getClientList($startFd, $pageSize);
            $numOfConnections = count($list);
            if ($numOfConnections === 0) {
                break;
            }

            $count += $numOfConnections;

            foreach ($list as $fd) {
                if ($fd > 0 && $this->server->isEstablished($fd)) {
                    call_user_func($handler, $fd);
                }
            }

            if ($numOfConnections < $pageSize) {
                break;
            }

            $startFd = end($fdList);
        }

        return $count;
    }

    public function on(string $eventName, callable $callback)
    {
        if (in_array($eventName, self::SYSTEM_EVENT)) {
            $this->server->on($eventName, $callback);
        } else {
            $this->router->addRoute($eventName, $callback);
        }
    }

    public function setFd(int $fd): void
    {
        $this->fd = $fd;
    }

    public function getFd(): int
    {
        return $this->fd;
    }

    public function newMessage(): Response
    {
        return $this->responseFactory->create($this);
    }

    public function toAll(): Response
    {
        return $this->responseFactory->create($this);
    }

    public function ping(string $message = ''): void
    {
        $pingFrame = new Frame();
        $pingFrame->opcode = WEBSOCKET_OPCODE_PING;
        $this->server->push($this->fd, $pingFrame);

        $this->sendTo($this->fd, (string) Type::PING->value);
    }

    public function pong(string $message = ''): void
    {
        $pongFrame = new Frame();
        // Setup a new data frame to send back a pong to the client
        $pongFrame->opcode = WEBSOCKET_OPCODE_PONG;
        $this->server->push($this->fd, $pongFrame);

        $this->sendTo($this->fd, (string) Type::PONG->value);
    }

    public function to(int|string $fd, int $type = Response::TO_TYPE_ROOM): Response
    {
        return $this->responseFactory->create($this)->to($fd, $type);
    }

    public function in(int|string $fd, int $type = Response::TO_TYPE_ROOM): Response
    {
        return $this->to($fd, $type);
    }

    public function emit(string $eventName, ...$args): void
    {
        $response = $this->responseFactory->create($this);
        if ($this->fd !== self::SYSTEM_FD) {
            $response->to($this->fd, Response::TO_TYPE_FD);
        }

        $response->emit($eventName, ...$args);
    }

    public function ack(int $num, ...$args): void
    {
        $response = $this->responseFactory->create($this);
        if ($this->fd !== self::SYSTEM_FD) {
            $response->to($this->fd, Response::TO_TYPE_FD);
        }

        $response->ack($num, ...$args);
    }

    public function broadcast(): Response
    {
        $response = $this->responseFactory->create($this);
        if ($this->fd !== self::SYSTEM_FD) {
            $response->except($this->fd, Response::TO_TYPE_FD);
        }

        return $response;
    }

    public function getInfo(): array|bool
    {
        return $this->server->getClientInfo($this->getFd());
    }

    public function join(string $roomName): void
    {
        if ($this->fd === self::SYSTEM_FD) {
            return;
        }

        $this->rooms->join($roomName, $this->fd);
    }

    public function leave(string $roomName): void
    {
        $this->rooms->leave($roomName, $this->fd);
    }

    public function getServer(): WebsocketServer
    {
        return $this->server;
    }

    public function setRequest(Request $request): void
    {
        $this->request = $request;
    }

    public function getRequest(): ?Request
    {
        return  $this->request;
    }

    public function setSession(Session $session): void
    {
        $this->session = $session;
    }

    public function getSession(): ?Session
    {
        return $this->session;
    }

    public function timeout(int $milliseconds, ...$args): Response
    {
        $response = $this->responseFactory->create($this);
        if ($this->fd !== self::SYSTEM_FD) {
            $response->to($this->fd, Response::TO_TYPE_FD);
        }

        return $response->timeout($milliseconds, ...$args);
    }
}
