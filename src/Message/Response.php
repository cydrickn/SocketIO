<?php

namespace Cydrickn\SocketIO\Message;

use Cydrickn\SocketIO\Enum\MessageType;
use Cydrickn\SocketIO\Enum\Type;
use Cydrickn\SocketIO\Service\FdFetcher;
use Cydrickn\SocketIO\Socket;
use Swoole\WebSocket\Frame;

class Response
{
    public const TO_TYPE_FD = 1;
    public const TO_TYPE_SID = 2;
    public const TO_TYPE_ROOM = 3;

    private int $fd = Socket::SYSTEM_FD;

    private array $receivers = [];

    private bool $dontSend = false;

    private array $excludes = [];

    private Frame|string $data = '';

    private int $sender = Socket::NO_SENDER;

    private bool $finish = true;

    private int $opcode = WEBSOCKET_OPCODE_TEXT;

    private bool $sent = false;

    private Type $type = Type::MESSAGE;

    private MessageType $messageType = MessageType::EVENT;

    private Socket $socket;

    private FdFetcher $fdFetcher;

    public static function new(Socket $socket, FdFetcher $fdFetcher, int $fd = Socket::SYSTEM_FD): static
    {
        $response = new static($socket);
        $response->fd = $fd;
        $response->sender = $fd;
        $response->fdFetcher = $fdFetcher;

        return $response;
    }

    private function __construct(Socket $socket)
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

    public function to(int|string $fd, int $type = Response::TO_TYPE_ROOM): self
    {
        $this->receivers = array_merge($this->receivers, $this->fdFetcher->find($fd, $type));
        if (empty($this->receivers)) {
            $this->dontSend = true;
        }

        return $this;
    }

    public function except(int|string $fd, int $type = Response::TO_TYPE_ROOM): self
    {
        $this->excludes = array_merge($this->excludes, $this->fdFetcher->find($fd, $type));

        return $this;
    }

    public function emit(string $eventName, ...$args): int
    {
        if ($this->sent) {
            throw new \Exception('This message already sent.');
        }

        if ($this->dontSend) {
            return 0;
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
