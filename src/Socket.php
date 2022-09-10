<?php

namespace Cydrickn\SocketIO;

use Cydrickn\SocketIO\Enum\Type;
use Cydrickn\SocketIO\Message\Request;
use Cydrickn\SocketIO\Message\Response;
use Cydrickn\SocketIO\Message\ResponseFactory;
use Cydrickn\SocketIO\Router\Router;
use Cydrickn\SocketIO\Room\RoomsInterface;
use Cydrickn\SocketIO\Session\Session;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ServerRequestInterface;
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

    public int $pingInterval = 25000;

    public int $pingTimeout = 20000;

    protected ResponseFactory $responseFactory;

    protected ?ServerRequestInterface $request = null;

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

    public function sendTo(int $receiver, Frame|string $data, int $opcode = WEBSOCKET_OPCODE_TEXT, bool $finish = true): bool
    {
        if (!$this->isEstablished($receiver)) {
            return  false;
        }

        return $this->server->push($receiver, $data, $opcode, $finish);
    }

    public function sendToAll(Frame|string $data, int $opcode = WEBSOCKET_OPCODE_TEXT, int $pageSize = 50): int
    {
        return $this->sendToSome($data, [], [], $opcode, $pageSize);
    }

    public function sendToSome(Frame|string $data, array $receivers = [], array $excludes = [], int $opcode = WEBSOCKET_OPCODE_TEXT, int $pageSize = 50): int
    {
        $count = 0;

        if (!empty($receivers) && !empty($excludes)) {
            $receivers = array_diff($receivers, $excludes);
            $excludes = [];
        }

        if (!empty($receivers)) {
            foreach ($receivers as $receiver) {
                $ok = $this->sendTo($receiver, $data, $opcode);
                $count += $ok ? 1 : 0;
            }

            return $count;
        }

        $this->eachConnection(function ($fd) use ($data,$opcode, $excludes, &$count) {
            if (in_array($fd, $excludes)) {
                return;
            }

            $ok = $this->sendTo($fd, $data, $opcode);
            $count += $ok ? 1 : 0;
        }, $pageSize);

        return $count;
    }

    public function send(Frame|string $data, array $receivers = [], array $excludes = [], int $opcode = WEBSOCKET_OPCODE_TEXT, int $pageSize = 50): int
    {
        if (count($receivers) === 1) {
            $ok = $this->sendTo($receivers[0], $data, $opcode);

            return $ok ? 1 : 0;
        }

        if (empty($receivers) && empty($excludes)) {
            return $this->sendToAll($data, $opcode);
        }

        return $this->sendToSome($data, $receivers, $excludes, $opcode, $pageSize);
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

    public function ping(): void
    {
        $this->sendTo($this->fd, (string) Type::PING->value);
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

    public function broadcast(): Response
    {
        $response = $this->responseFactory->create($this);
        if ($this->fd !== self::SYSTEM_FD) {
            $response->except($this->fd, Response::TO_TYPE_FD);
        }

        return $response;
    }

    public function getInfo(): array
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

    public function setRequest(ServerRequestInterface $request): void
    {
        $this->request = $request;
    }

    public function getRequest(): ?ServerRequestInterface
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
}
