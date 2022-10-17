<?php

namespace Cydrickn\SocketIO\Manager;

class AckManager
{
    private $ackCallback = [];

    public function add(string $key, callable $callback): void
    {
        $this->ackCallback[$key] = $callback;
    }

    public function has($key): bool
    {
        return array_key_exists($key, $this->ackCallback);
    }

    public function get($key)
    {
        $callback = $this->ackCallback[$key];
        unset($this->ackCallback[$key]);

        return $callback;
    }
}
