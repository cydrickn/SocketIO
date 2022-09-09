<?php

namespace Cydrickn\SocketIO;

use Cydrickn\SocketIO\Enum\Type;
use Cydrickn\SocketIO\Message\Response;
use Cydrickn\SocketIO\Router\Router;
use Swoole\Timer;
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

    public function __construct(WebsocketServer $server, Router $router)
    {
        $this->server = $server;
        $this->router = $router;
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
        return Response::new($this, $this->fd);
    }

    public function toAll(): Response
    {
        return Response::new($this, $this->getFd());
    }

    public function ping(): void
    {
        $this->sendTo($this->fd, (string) Type::PING->value);
    }

    public function to(int $fd): Response
    {
        return Response::new($this, $this->getFd())->to($fd);
    }

    public function emit(string $eventName, ...$args): void
    {
        $response = Response::new($this, $this->fd);
        if ($this->fd === self::SYSTEM_FD) {
            $response->to($this->fd);
        }

        $response->emit($eventName, ...$args);
    }

    public function broadcast(): Response
    {
        $response = Response::new($this, $this->fd);
        $response->except($this->fd);

        return $response;
    }

    public function getInfo(): array
    {
        return $this->server->getClientInfo($this->getFd());
    }
}
