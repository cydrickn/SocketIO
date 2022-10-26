<?php

namespace Cydrickn\SocketIO\Message;

use Cydrickn\SocketIO\Table\Timer;
use Cydrickn\SocketIO\Enum\MessageType;
use Cydrickn\SocketIO\Enum\Type;
use Cydrickn\SocketIO\Manager\AckManager;
use Cydrickn\SocketIO\Manager\SocketManager;
use Cydrickn\SocketIO\Service\FdFetcher;
use Cydrickn\SocketIO\Socket;
use MongoDB\BSON\ObjectId;
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

    private AckManager $ackManager;

    private SocketManager $socketManager;

    private int $timeout = 0;

    private array $timeoutParams = [];

    private Timer $timer;

    public static function new(
        Socket $socket,
        FdFetcher $fdFetcher,
        AckManager $ackManager,
        SocketManager $socketManager,
        Timer $timer,
        int $fd = Socket::SYSTEM_FD,
    ): static {
        $response = new static($socket);
        $response->fd = $fd;
        $response->sender = $fd;
        $response->fdFetcher = $fdFetcher;
        $response->ackManager = $ackManager;
        $response->socketManager = $socketManager;
        $response->timer = $timer;

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

        $dataMessage = $this->type->value;
        if ($this->type === Type::MESSAGE) {
            $dataMessage .= $this->messageType->value;
        }

        $arguments = $args;
        $callback = function ($fd) {
        };
        if (count($args) > 0 && is_callable($args[count($args) - 1])) {
            $ackCallback = $args[count($args) - 1];
            array_pop($arguments);
            $group = new ObjectId();
            $callback = function ($fd, &$data) use ($ackCallback, $dataMessage, $eventName, $arguments, $group) {
                $ackNum = $this->socketManager->incrementAck($fd);
                $this->ackManager->add($fd . '::' . $ackNum, $ackCallback, $this->timeout, (string) $group);
                $data = $dataMessage . $ackNum . json_encode([$eventName, ...$arguments]);

                if ($this->timeout > 0) {
                    $this->timer->interval('ack::' . $group, $this->timeout, function ($info, $ackId, ...$args) {
                        $ackCallback = $this->ackManager->get($ackId);
                        call_user_func($ackCallback['callback'], true, ...$args);
                        $this->timer->clear($info['id']);
                    }, $fd . '::' . $ackNum, ...$this->timeoutParams);
                }
            };
        }

        $message = json_encode([$eventName, ...$arguments]);
        $data = $dataMessage . $message;
        $this->sent = true;

        return $this->socket->send($data, $this->receivers, $this->excludes, WEBSOCKET_OPCODE_TEXT, 50, $callback);
    }

    public function ack(int $callbackNum, ...$args): int
    {
        if ($this->sent) {
            throw new \Exception('This message already sent.');
        }

        if ($this->dontSend) {
            return 0;
        }

        $data = $this->type->value . MessageType::ACK->value . $callbackNum;
        $message = json_encode([...$args]);
        $data .= $message;

        $this->sent = true;

        return $this->socket->send($data, $this->receivers, $this->excludes);
    }

    public function timeout(int $milliseconds, ...$args): self
    {
        $this->timeout = $milliseconds;
        $this->timeoutParams = $args;

        return $this;
    }
}
