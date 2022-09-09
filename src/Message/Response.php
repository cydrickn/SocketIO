<?php

namespace Cydrickn\SocketIO\Message;

use Cydrickn\SocketIO\Enum\MessageType;
use Cydrickn\SocketIO\Enum\Type;
use Cydrickn\SocketIO\Socket;
use Swoole\WebSocket\Frame;

class Response
{
    private int $fd = Socket::SYSTEM_FD;

    private array $receivers = [];

    private array $excludes = [];

    private Frame|string $data = '';

    private int $sender = Socket::NO_SENDER;

    private bool $finish = true;

    private int $opcode = WEBSOCKET_OPCODE_TEXT;

    private bool $sent = false;

    private Type $type = Type::MESSAGE;

    private MessageType $messageType = MessageType::EVENT;

    private Socket $socket;

    public static function new(Socket $socket, int $fd = Socket::SYSTEM_FD): static
    {
        $response = new static($socket);
        $response->fd = $fd;
        $response->sender = $fd;

        return $response;
    }

    public function __construct(Socket $socket)
    {
        $this->socket = $socket;
    }

    public function getFd(): int
    {
        return $this->fd;
    }

    public function getSender(): int
    {
        return $this->sender;
    }

    public function setSender(int $sender): self
    {
        $this->sender = $sender;
    }

    public function noSender(): self
    {
        $this->sender = Socket::NO_SENDER;
    }

    public function to(int $fd): self
    {
        $this->receivers[] = $fd;

        return $this;
    }

    public function except(int $fd): self
    {
        $this->excludes[] = $fd;

        return $this;
    }

    public function emit(string $eventName, ...$args): int
    {
        if ($this->sent) {
            throw new \Exception('This message already sent.');
        }

        $data = $this->type->value;
        if ($this->type === Type::MESSAGE) {
            $data .= $this->messageType->value;
        }

        $message = json_encode([$eventName, ...$args]);
        $data .= $message;

        $this->sent = true;

        return $this->socket->send($data, $this->receivers, $this->excludes);
    }
}
