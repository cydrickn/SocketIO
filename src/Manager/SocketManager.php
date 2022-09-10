<?php

namespace Cydrickn\SocketIO\Manager;

use Cydrickn\SocketIO\Helper\IdGenerator;
use Cydrickn\SocketIO\Socket;
use Swoole\Table;

class SocketManager
{
    private array $sockets = [];
    private Table $socketTable;
    private array $characters;
    private int $charactersLen;
    private array $shuffledChars;

    public function __construct()
    {
        $this->socketTable = new Table(1024);
        $this->socketTable->column('fd', Table::TYPE_INT);
        $this->socketTable->column('connected', Table::TYPE_INT);
        $this->socketTable->create();

        $this->characters = str_split('0123456789abcdefghijklmnopqrstuvwxyz');
        $this->charactersLen = count($this->characters);
        $this->shuffledChars = str_split('95v86b0ni4latze32gcxoprm71qwhsjdfuky');
    }

    public function add(Socket $socket): self
    {
        $id = IdGenerator::generateFromSocket($socket);
        $socket->sid = $id;

        $this->socketTable->set($socket->sid, [
            'fd' => $socket->getFd(),
            'connected' => 1,
        ]);
        $this->sockets[$socket->getFd()] = $socket;

        return $this;
    }

    public function del(int $fd): void
    {
        if ($this->has($fd)) {
            unset($this->sockets[$fd]);
        }
    }

    public function get(int $fd): ?Socket
    {
        return $this->has($fd) ? $this->sockets[$fd] : null;
    }

    public function has(int $fd): bool
    {
        return array_key_exists($fd, $this->sockets);
    }
}
