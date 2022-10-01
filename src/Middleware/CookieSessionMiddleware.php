<?php

namespace Cydrickn\SocketIO\Middleware;

use Cydrickn\SocketIO\Helper\IdGenerator;
use Cydrickn\SocketIO\Server;
use Cydrickn\SocketIO\Socket;
use Swoole\Http\Response;

class CookieSessionMiddleware implements HandshakeMiddlewareInterface
{
    public function __construct(private Server $server, private string $cookieName = 'PHPSESID')
    {
    }

    public function handle(Socket $socket, Response $response, callable $next)
    {
        $request = $socket->getRequest();
        $sessionId = $request->cookie[$this->cookieName] ?? IdGenerator::generateFromSocket($socket, [['rand', 4]]);

        $session = $this->server->getSessionStorage()->getSession($sessionId);
        $session->commit();

        $socket->setSession($session);

        $response->setCookie($this->cookieName, $sessionId);

        $next();
    }

    public function getPriority(): int
    {
        return 500;
    }
}
