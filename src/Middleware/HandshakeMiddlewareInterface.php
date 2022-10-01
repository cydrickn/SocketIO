<?php

namespace Cydrickn\SocketIO\Middleware;

use Cydrickn\SocketIO\Socket;
use Swoole\Http\Response;

interface HandshakeMiddlewareInterface extends MiddlewareInterface
{
    public function handle(Socket $socket, Response $response, callable $next);
}
