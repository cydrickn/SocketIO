<?php

namespace Cydrickn\SocketIO\Manager;

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
        $this->generateId($socket);
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

    private function generateId(Socket $socket): string
    {
        $info = $socket->getInfo();
        $workerId = $info['worker_id'] ?? 0;
        $socketFd = $info['socket_fd'];
        $remoteIps = explode('.', $info['remote_ip']);
        $serverFd = $info['server_fd'];
        $connectionStrs = str_split((string) $info['connect_time'], 3);

        $id = $this->getChar($workerId) . $this->random() . $socketFd . $this->random();
        foreach ($remoteIps as $remoteIp) {
            $id .= $this->getChar(array_sum(str_split($remoteIp)));
        }
        $id .= $this->random() . $this->getChar($serverFd) . $this->random(2);

        foreach ($connectionStrs as $connectionStr) {
            $id .= $this->getChar(array_sum(str_split($connectionStr)));
        }

        $id .= $this->random(2);

        $socket->sid = $id;

        return $id;
    }

    private function getChar(int|null $index): string
    {
        if ($index === null) {
            $index = 0;
        }

        if ($index >= $this->charactersLen) {
            $index = $this->charactersLen - 1;
        }

        return $this->shuffledChars[$index];
    }

    private function random(int $limit = 1, bool $shuffle = true): string
    {
        $str = '';
        for ($i = 0; $i < $limit; $i++) {
            if ($shuffle) {
                $str .= $this->shuffledChars[rand(0, $this->charactersLen - 1)];
            } else {
                $str .= $this->characters[rand(0, $this->charactersLen - 1)];
            }
        }

        return $str;
    }

    public function getFdBySid(string $sid): int|null
    {
        $fd = $this->socketTable->get($sid, 'fd');
        if ($fd === false) {
            return null;
        }

        return (int) $fd;
    }
}
