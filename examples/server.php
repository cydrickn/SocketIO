<?php

use Cydrickn\SocketIO\Storage\Adapter\RoomsTable;

require __DIR__ . '/../vendor/autoload.php';

$server = new \Cydrickn\SocketIO\Server([
    'host' => '0.0.0.0',
    'port' => 8000,
    'mode' => SWOOLE_PROCESS,
    'serve_http' => true,
    'settings' => [
        \Swoole\Constant::OPTION_WORKER_NUM => swoole_cpu_num() * 2,
        \Swoole\Constant::OPTION_ENABLE_STATIC_HANDLER => true,
        \Swoole\Constant::OPTION_DOCUMENT_ROOT => dirname(__DIR__).'/examples'
    ]
], sessionStorage: new \Cydrickn\SocketIO\Session\SessionsNative(__DIR__ . '/sessions'));

$server->on('Started', function (\Cydrickn\SocketIO\Server $server) {
    echo 'Websocket Rock is now listening in ' . $server->getHost() . ':' . $server->getPort() . PHP_EOL;
});

$server->on('Request', function (\Swoole\Http\Request $request, \Swoole\Http\Response $response) {
    $index = file_get_contents(__DIR__ . '/index.html');
    $response->end($index);
});

$middleware = new \Cydrickn\SocketIO\Middleware\CookieSessionMiddleware($server);
$server->use([$middleware, 'handle'], true);

$server->on('connection', function (\Cydrickn\SocketIO\Socket $socket) {
    $socket->broadcast()->emit('chat message', 'Client ' . $socket->sid . ' has Joined');
    $socket->emit('chat message', 'Welcome to Socket IO Websocket');
});

$server->on('chat message', function (\Cydrickn\SocketIO\Socket $socket, string $message) {
    $socket->emit('chat message', $message);
});

$server->start();
