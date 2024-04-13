<?php

namespace Cydrickn\SocketIO\Session\Traits;

use Cydrickn\SocketIO\Session\Session;
use Swoole\Coroutine;
use Swoole\Coroutine\Channel;

trait ChannelTrait
{
    private Channel $channel;
    private int $cid;
    private bool $stopped = false;

    public function setChannel(int $capacity)
    {
        $this->channel = new Channel($capacity);
    }

    public function stop(): void
    {
        $this->stopped = true;
    }

    public function start(): void
    {
        $this->stopped = false;
        $this->setChannel(10);

        Coroutine::create(function () {
            $cid = Coroutine::getcid();
            while (!$this->stopped) {
                $data = $this->channel->pop(1);
                list($action, $sessionId, $field, $data) = $data;
                if ($sessionId === null) {
                    continue;
                }
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
            $this->channel->close();
            Coroutine::cancel($cid);
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
