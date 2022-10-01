<?php

namespace Cydrickn\SocketIO\Middleware;

interface MiddlewareInterface
{
    public function getPriority(): int;
}
