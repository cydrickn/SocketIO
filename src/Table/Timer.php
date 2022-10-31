<?php

namespace Cydrickn\SocketIO\Table;

use Swoole\Table;
use Swoole\Timer as SwooleTimer;

class Timer
{
    private Table $table;

    public function __construct()
    {
        $this->table = new Table(1024);
        $this->table->column('timerId', Table::TYPE_INT);
        $this->table->column('called', Table::TYPE_INT);
        $this->table->create();
    }

    public function timeout($id, $timeout, callable $callback, ...$params): int
    {
        $timerId = SwooleTimer::tick($timeout, function (int $timerId, $callback, $id, ...$params) {
            $called = $this->table->incr($id, 'called');
            call_user_func($callback, ['timerId' => $timerId, 'id' => $id, 'called' => $called], ...$params);
            $this->clear($id);
        }, $callback, $id, ...$params);

        $this->table->set($id, ['timerId' => $timerId, 'called' => 0]);

        return $timerId;
    }

    public function interval($id, $timeout, callable $callback, ...$params): int
    {
        $timerId = SwooleTimer::tick($timeout, function (int $timerId, $callback, $id, ...$params) {
            $called = $this->table->incr($id, 'called');
            call_user_func($callback, ['timerId' => $timerId, 'id' => $id, 'called' => $called], ...$params);
        }, $callback, $id, ...$params);

        $this->table->set($id, ['timerId' => $timerId, 'called' => 0]);

        return $timerId;
    }

    public function clear($id): void
    {
        $timer = $this->table->get($id, 'timerId');

        SwooleTimer::clear($timer);
        $this->table->del($id);
    }

    public function exists(string $id): bool
    {
        return $this->table->exists($id);
    }
}
