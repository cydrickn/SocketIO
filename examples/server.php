<?php

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
]);

$server->on('WorkerStart', function () use ($server) {

});

$server->on('Request', function (\Swoole\Http\Request $request, \Swoole\Http\Response $response) {
    $index = file_get_contents(__DIR__ . '/index.html');
    $response->end($index);
});

$server->on('Start', function () use ($server) {
    echo 'Websocket is now listening in ' . $server->getHost() . ':' . $server->getPort() . PHP_EOL;
});

$server->on('connection', function (\Cydrickn\SocketIO\Socket $socket) {
    $socket->toAll()->emit('chat message', 'Socket ' . $socket->getFd() . ' has Joined');
});

$server->on('chat message', function (\Cydrickn\SocketIO\Socket $socket, string $message) {
    $socket->toAll()->emit('chat message', $message);
});

$server->on('disconnect', function (\Cydrickn\SocketIO\Socket $socket) {
//    $socket->broadcast()->emit('chat message', $socket->getFd() . ' have been disconnected');
});

$server->start();
