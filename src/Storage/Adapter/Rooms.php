<?php

namespace Cydrickn\SocketIO\Storage\Adapter;

use Cydrickn\SocketIO\Storage\RoomsInterface;

use Swoole\Coroutine;
use Swoole\Coroutine\Channel;
use Swoole\Process;
use Swoole\Table as SwooleTable;
use Swoole\WebSocket\Server;

class Rooms implements RoomsInterface
{
    private SwooleTable $table;
    private Channel $roomsChannel;
    private Process $process;

    public function __construct(private Server $server)
    {
        $this->table = new SwooleTable(2048);
        $this->table->column('fds', SwooleTable::TYPE_STRING);
        $this->table->create();

        $this->roomsChannel = new Channel(10);
        $this->dispatch();
    }

    public function add(string $roomName): void
    {
        $this->table->set($roomName, ['fds' => '[]']);
    }

    public function remove(string $roomName): void
    {
        $this->table->del($roomName);
    }

    public function dispatch()
    {
        $this->process = new Process(function () {
            Coroutine\run(function() {
                while (true) {
                    if ($this->roomsChannel->isEmpty()) {
                        continue;
                    }
                    $data = $this->roomsChannel->pop();
                    Coroutine::create(function ($data) {
                        list($type, $roomName, $fd) = $data;
                        if ($type === 'join') {
                            $this->executeJoin($roomName, $fd);
                        } elseif ($type === 'leave') {
                            $this->executeLeave($roomName, $fd);
                        }
                    }, $data);
                }
            });
        });

        $this->server->addProcess($this->process);
    }

    public function join(string $roomName, int $fd): void
    {
        $this->roomsChannel->push(['join', $roomName, $fd]);
    }

    public function leave(string $roomName, int $fd): void
    {
        $this->roomsChannel->push(['leave', $roomName, $fd]);
    }

    private function executeJoin(string $roomName, int $fd): void
    {
        $data = $this->table->get($roomName, 'fds');
        if ($data === false) {
            $this->add($roomName);
            $data = '[]';
        }

        $fds = json_decode($data, true);
        $fds[] = $fd;

        $this->table->set($roomName, ['fds' => json_encode($fds)]);
    }

    private function executeLeave(string $roomName, int $fd)
    {
        $data = $this->table->get($roomName, 'fds');
        if ($data === false) {
            return;
        }

        $fds = json_decode($data, true);
        $fds = array_values(array_filter($fds, function ($value) use ($fd) {
            return $value !== $fd;
        }));

        $this->table->set($roomName, ['fds' => json_encode($fds)]);
    }

    public function getFds(string $roomName): array
    {
        $data = $this->table->get($roomName, 'fds');
        if ($data === false) {
            return [];
        }

        return json_decode($data, true);
    }
}
