<?php

namespace Cydrickn\SocketIO\Message;

use Cydrickn\SocketIO\Enum\MessageType;
use Cydrickn\SocketIO\Enum\Type;
use Cydrickn\SocketIO\Socket;
use Swoole\WebSocket\Frame;

class Request
{
    protected const MESSAGE_PATTERN = '/([0-9]+)[\/]*([a-zA-Z0-9]*)[,]*([\s\S]*)/';

    protected string $path;
    protected int $fd;
    protected Socket $socket;
    protected array $data;
    protected Type $type = Type::NONE;
    protected MessageType $messageType = MessageType::EVENT;
    protected int $callbackNum = -1;

    public static function fromFrame(Frame $frame, Socket $socket): Request
    {
        preg_match_all(static::MESSAGE_PATTERN, $frame->data, $matches);
        list(, $code, $namespace, $packet) = $matches;
        $code = array_pad(str_split(current($code)), 3, null);
        $namespace = current($namespace);
        $packet = current($packet);
        $type = Type::NONE;
        $messageType = MessageType::EVENT;
        $packetData = json_decode($packet, true);
        $path = $namespace ? $namespace . '/' : '';
        $callbackNum = -1;

        if ($code[0] !== null) {
            $type = Type::from((int) $code[0]);
        }

        if ($code[1] !== null && $type === Type::MESSAGE) {
            $messageType = MessageType::from($code[1]);
            if (count($code) >= 3) {
                $callbackNum = (int) implode('', array_slice($code, 2));
            }
        }

        $data = [];
        if (!empty($packetData)) {
            $path .= $packetData[0];
            foreach ($packetData as $key => $datum) {
                if ($key === 0 && $messageType !== MessageType::ACK) {
                    continue;
                }
                $data[] = $datum;
            }
        } else {
            $path = '';
            $data = ['probe'];
        }

        switch ($type) {
            case Type::PING:
                $path = 'ping';
                break;
            case Type::UPGRADE:
                $path = 'upgrade';
                break;
        }

        $request = new static($socket, $path, $frame->fd, $data, $callbackNum);
        $request->setType($type);
        $request->setMessageType($messageType);

        return $request;
    }

    public function __construct(Socket $socket, string $path, int $fd, array $data, int $callbackNum = -1)
    {
        $this->path = $path;
        $this->fd = $fd;
        $this->socket = $socket;
        $this->data = $data;
        $this->callbackNum = $callbackNum;
    }

    public function getType(): Type
    {
        return $this->type;
    }

    public function getMessageType(): MessageType
    {
        return $this->messageType;
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function getFd(): int
    {
        return $this->fd;
    }

    public function getSocket(): Socket
    {
        return $this->socket;
    }

    public function getData(): array
    {
        $data = $this->data;
        if ($this->hasCallback() && $this->messageType !== MessageType::ACK) {
            $data[] = function (...$args) {
                $this->socket->ack($this->getCallbackNum(), ...$args);
            };
        }

        return $data;
    }

    public function setType(Type $type): void
    {
        $this->type = $type;
    }

    public function setMessageType(MessageType $messageType): void
    {
        $this->messageType = $messageType;
    }

    public function hasCallback(): bool
    {
        return $this->callbackNum >= 0;
    }

    public function getCallbackNum(): int
    {
        return $this->callbackNum;
    }
}
