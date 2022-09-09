<?php

namespace Cydrickn\SocketIO\Manager;

use Cydrickn\SocketIO\Socket;
use Swoole\Table;
use Swoole\Timer;

class PingTimerManager
{
    private Table $table;

    public function __construct()
    {
        $this->table = new Table(1024);
        $this->table->column('timerId', Table::TYPE_INT);
        $this->table->create();
    }

    public function createForSocket(Socket $socket)
    {
        $timer = Timer::tick($socket->pingInterval, function (int $fd) use ($socket) {
            $socket->ping();
        }, $socket->getFd());

        $this->table->set($socket->getFd(), ['timerId' => $timer]);
    }

    public function remove(int $fd): void
    {
        $timerId = $this->table->get($fd, 'timerId');
        Timer::clear($timerId);
        $this->table->del($fd);
    }
}
