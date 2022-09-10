<?php

namespace Cydrickn\SocketIO\Session;

use Cydrickn\SocketIO\Session\Traits\ChannelTrait;
use Swoole\Coroutine;
use Swoole\Coroutine\Channel;
use Swoole\Table;

class SessionsTable implements SessionStorageInterface
{
    use ChannelTrait;

    private Table $table;

    public function __construct(array $columns = [])
    {
        $this->table = new Table(2048);
        foreach ($columns as $column) {
            $this->table->column($column[0], $column[1], $column[2] ?? 0);
        }
        $this->table->create();

        $this->channel = new Channel(10);
    }

    public function get(string $sessionId): array|null
    {
        $data = $this->table->get($sessionId);
        if ($data === false) {
            return null;
        }

        return $data;
    }

    public function getField(string $sessionId, string $field, mixed $default): mixed
    {
        $data = $this->table->get($sessionId, $field);

        return $data === false ? $default : $data;
    }

    public function del(string $sessionId): void
    {
        $this->table->del($sessionId);
    }

    protected function setData(string $sessionId, array $data): void
    {
        $this->table->set($sessionId, $data);
    }
}
