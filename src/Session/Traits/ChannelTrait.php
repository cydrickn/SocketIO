<?php

namespace Cydrickn\SocketIO\Session\Traits;

use Cydrickn\SocketIO\Session\Session;
use Swoole\Coroutine;
use Swoole\Coroutine\Channel;

trait ChannelTrait
{
    private Channel $channel;

    public function setChannel(int $capacity)
    {
        $this->channel = new Channel($capacity);
    }

    public function start(): void
    {
        $this->setChannel(10);
        Coroutine::create(function () {
            while (true) {
                $data = $this->channel->pop();
                list($action, $sessionId, $field, $data) = $data;
                $sessionData = $this->get($sessionId);
                if ($action === 'del') {
                    unset($sessionData[$field]);
                } elseif ($action === 'set' && $field === null) {
                    $sessionData = $data;
                } elseif ($action === 'set' && $field !== null) {
                    $sessionData[$field] = $data;
                }
                $this->setData($sessionId, $sessionData);
            }
        });
    }

    abstract public function get(string $sessionId): array|null;

    abstract protected function setData(string $sessionId, array $data): void;

    public function set(string $sessionId, array $data): void
    {
        $this->channel->push(['set', $sessionId, null, $data]);
    }

    public function setField(string $sessionId, string $field, mixed $data): void
    {
        $this->channel->push(['set', $sessionId, $field, $data]);
    }

    public function delField(string $sessionId, string $field): void
    {
        $this->channel->push(['del', $sessionId, $field, null]);
    }

    public function getSession(string $sessionId): Session
    {
        $data = $this->get($sessionId);

        return new Session($this, $sessionId, $data ?? []);
    }
}
