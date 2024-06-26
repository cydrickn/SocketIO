<?php

namespace Cydrickn\SocketIO\Room;

use Swoole\Coroutine;
use Swoole\Coroutine\Channel;
use Swoole\Table as SwooleTable;
use Swoole\WebSocket\Server;

class RoomsTable implements RoomsInterface
{
    private SwooleTable $table;
    private Channel $roomsChannel;
    private bool $stopped = false;

    protected array $roomEvents = [
        'create-room' => [],
        'delete-room' => [],
        'join-room' => [],
        'leave-room' => [],
    ];

    public function __construct()
    {
        $this->table = new SwooleTable(2048);
        $this->table->column('fds', SwooleTable::TYPE_STRING, 2048 * 10);
        $this->table->create();
    }

    public function add(string $roomName): void
    {
        $this->table->set($roomName, ['fds' => '[]']);
        $this->dispatch('create-room', $roomName);
    }

    public function remove(string $roomName): void
    {
        $this->table->del($roomName);
        $this->dispatch('delete-room', $roomName);
    }

    public function stop(): void
    {
        $this->stopped = true;
    }

    public function start(): void
    {
        $this->stopped = false;
        $this->roomsChannel = new Channel(10);
        Coroutine::create(function () {
            $cid = Coroutine::getcid();
            while (!$this->stopped) {
                $data = $this->roomsChannel->pop(1);
                list($type, $roomName, $fd) = $data;
                if ($type === 'join') {
                    $this->executeJoin($roomName, $fd);
                } elseif ($type === 'leave') {
                    $this->executeLeave($roomName, $fd);
                }
            }
            $this->roomsChannel->close();
            Coroutine::cancel($cid);
        });
    }

    public function join(string $roomName, int $fd): void
    {
        $this->roomsChannel->push(['join', $roomName, $fd]);
    }

    public function leave(string $roomName, int $fd): void
    {
        Coroutine::create(function ($roomName, $fd) {
            $this->roomsChannel->push(['leave', $roomName, $fd], 1);
        }, $roomName, $fd);
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
        $this->dispatch('join-room', $roomName, $fd);
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

        if (empty($fds)) {
            $this->table->del($roomName);
        } else {
            $this->table->set($roomName, ['fds' => json_encode($fds)]);
        }
        $this->dispatch('leave-room', $roomName, $fd);
    }

    public function getFds(string $roomName): array
    {
        $data = $this->table->get($roomName, 'fds');
        if ($data === false) {
            return [];
        }

        return json_decode($data, true);
    }

    public function getFdRooms(int $fd): array
    {
        $rooms = [];
        foreach ($this->table as $key => $row) {
            $fds = json_decode($row['fds'], true);
            if (in_array($fd, $fds)) {
                $rooms[] = $key;
            }
        }

        return $rooms;
    }

    public function count(): int
    {

        return $this->table->count();
    }

    public function on(string $event, callable $callback): void
    {
        $this->roomEvents[$event][] = $callback;
    }

    public function dispatch(string $event, ...$args): void
    {
        Coroutine::create(function () use ($event, $args) {
            foreach ($this->roomEvents[$event] as $roomEvent) {
                call_user_func_array($roomEvent, $args);
            }
        });
    }
}
