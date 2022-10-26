<?php

namespace Cydrickn\SocketIO\Manager;

use Cydrickn\SocketIO\Error\Error;

class AckManager
{
    public const ERR_NOT_EXISTS = 10001;

    private array $ackCallback = [];
    private array $keyGroups = [];

    public function add(string $key, callable $callback, int $timeout = 0, string $group = ''): void
    {
        $group = $group === '' ? $key : $group;
        if (!array_key_exists($group, $this->ackCallback)) {
            $this->ackCallback[$group] = ['callback' => $callback, 'timeout' => $timeout, 'group' => $group];
        }
        $this->keyGroups[$key] = $group;
    }

    public function has($key): bool
    {
        if (!array_key_exists($key, $this->keyGroups)) {
            return false;
        }

        $group = $this->keyGroups[$key];

        return array_key_exists($group, $this->ackCallback);
    }

    public function get($key): array
    {
        if (!$this->has($key)) {
            throw new Error('The acknowledge not exists: ' . $key, self::ERR_NOT_EXISTS);
        }

        $group = $this->keyGroups[$key];
        unset($this->keyGroups[$key]);
        $callback = $this->ackCallback[$group];
        unset($this->ackCallback[$group]);

        return $callback;
    }
}
