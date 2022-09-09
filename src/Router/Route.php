<?php

namespace Cydrickn\SocketIO\Router;

class Route
{
    protected mixed $callback;
    protected string $path;

    public function __construct(callable $callback, string $path)
    {
        $this->callback = $callback;
        $this->path = $path;
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function getCallback(): mixed
    {
        return $this->callback;
    }
}
