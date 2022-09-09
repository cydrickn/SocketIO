<?php

namespace Cydrickn\SocketIO\Manager;

use Cydrickn\SocketIO\Socket;
use Swoole\Table;
use Swoole\Timer;

class PingTimerManager
{
    private Table $table;
    private Table $timeoutTable;

    public function __construct()
    {
        $this->table = new Table(1024);
        $this->table->column('timerId', Table::TYPE_INT);
        $this->table->create();

        $this->timeoutTable = new Table(1024);
        $this->timeoutTable->column('timerId', Table::TYPE_INT);
        $this->timeoutTable->create();
    }

    public function createForSocket(Socket $socket)
    {
        $timer = Timer::tick($socket->pingInterval, function () use ($socket) {
            $socket->ping();
            $timeout = Timer::after($socket->pingTimeout, function () use ($socket) {
                $callback = $socket->getServer()->getCallback('PingTimeout');
                if (is_callable($callback)) {
                    call_user_func($callback, $socket);
                }
                $this->removeTimeout($socket->getFd());
                $this->remove($socket->getFd());
            });
            $this->timeoutTable->set($socket->getFd(), ['timerId' => $timeout, 'timoutId' => 0]);
        });

        $this->table->set($socket->getFd(), ['timerId' => $timer]);
    }

    public function removeTimeout(int $fd): void
    {
        $timerId = $this->timeoutTable->get($fd, 'timerId');
        Timer::clear($timerId);
        $this->timeoutTable->del($fd);
    }

    public function remove(int $fd): void
    {
        $timerId = $this->table->get($fd, 'timerId');
        Timer::clear($timerId);
        $this->table->del($fd);
    }
}
