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

    public function __construct()
    {
        $this->table = new SwooleTable(2048);
        $this->table->column('fds', SwooleTable::TYPE_STRING, 2048 * 10);
        $this->table->create();

        $this->roomsChannel = new Channel(10);
    }

    public function add(string $roomName): void
    {
        $this->table->set($roomName, ['fds' => '[]']);
    }

    public function remove(string $roomName): void
    {
        $this->table->del($roomName);
    }

    public function start(): void
    {
        Coroutine::create(function () {
            while (true) {
                $data = $this->roomsChannel->pop();
                list($type, $roomName, $fd) = $data;
                if ($type === 'join') {
                    $this->executeJoin($roomName, $fd);
                } elseif ($type === 'leave') {
                    $this->executeLeave($roomName, $fd);
                }
            }
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
}
