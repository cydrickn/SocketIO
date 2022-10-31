<?php

namespace Cydrickn\SocketIO\Table;

use Cydrickn\SocketIO\Server;
use Swoole\Table;
use Swoole\Timer as SwooleTimer;

class Timer
{
    private Table $table;

    public function __construct(private Server $server)
    {
        $this->table = new Table(1024);
        $this->table->column('timerId', Table::TYPE_INT);
        $this->table->column('called', Table::TYPE_INT);
        $this->table->column('worker', Table::TYPE_INT);
        $this->table->column('paused', Table::TYPE_INT);
        $this->table->create();
    }

    public function timeout($id, $timeout, callable $callback, ...$params): int
    {
        $timerId = SwooleTimer::tick($timeout, function (int $timerId, $callback, $id, ...$params) {
            $paused = $this->table->get($id, 'paused');
            if ($paused === 1) {
                return;
            }
            $called = $this->table->incr($id, 'called');
            call_user_func($callback, ['timerId' => $timerId, 'id' => $id, 'called' => $called], ...$params);
            $this->clear($id);
        }, $callback, $id, ...$params);

        $this->table->set($id, [
            'timerId' => $timerId,
            'called' => 0,
            'worker' => $this->server->getServer()->getWorkerId(),
            'paused' => 0
        ]);

        return $timerId;
    }

    public function interval($id, $timeout, callable $callback, ...$params): int
    {
        $timerId = SwooleTimer::tick($timeout, function (int $timerId, $callback, $id, ...$params) {
            $paused = $this->table->get($id, 'paused');
            if ($paused === 1) {
                return;
            }
            $called = $this->table->incr($id, 'called');
            call_user_func($callback, ['timerId' => $timerId, 'id' => $id, 'called' => $called], ...$params);
        }, $callback, $id, ...$params);

        $this->table->set($id, [
            'timerId' => $timerId,
            'called' => 0,
            'worker' =>  $this->server->getServer()->getWorkerId(),
            'paused' => 0
        ]);

        return $timerId;
    }

    public function resume($id): void
    {
        $timer = $this->table->get($id);
        if (!$timer) {
            return;
        }

        if ($timer['worker'] !== $this->server->getServer()->getWorkerId()) {
            $this->server->getServer()->sendMessage(json_encode([
                'id' => $id,
                'type' => 'timer:resume',
            ]), $timer['worker']);
            return;
        }

        $timer['pause'] = 0;
        $this->table->set($id, $timer);
    }

    public function pause($id): void
    {
        $timer = $this->table->get($id);
        if (!$timer) {
            return;
        }

        if ($timer['worker'] !== $this->server->getServer()->getWorkerId()) {
            $this->server->getServer()->sendMessage(json_encode([
                'id' => $id,
                'type' => 'timer:pause',
            ]), $timer['worker']);
            return;
        }

        $timer['pause'] = 1;
        $this->table->set($id, $timer);
    }

    public function clear($id): void
    {
        $timer = $this->table->get($id);
        if (!$timer) {
            return;
        }

        if ($timer['worker'] !== $this->server->getServer()->getWorkerId()) {
            $this->server->getServer()->sendMessage(json_encode([
                'id' => $id,
                'type' => 'timer:clear',
            ]), $timer['worker']);
            return;
        }

        SwooleTimer::clear($timer['timerId']);
        $this->table->del($id);
    }

    public function exists(string $id): bool
    {
        return $this->table->exists($id);
    }
}
