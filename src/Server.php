<?php

namespace Cydrickn\SocketIO;

use Cydrickn\SocketIO\Enum\MessageType;
use Cydrickn\SocketIO\Enum\Type;
use Cydrickn\SocketIO\Manager\PingTimerManager;
use Cydrickn\SocketIO\Manager\SocketManager;
use Cydrickn\SocketIO\Message\Request as MessageRequest;
use Cydrickn\SocketIO\Message\ResponseFactory;
use Cydrickn\SocketIO\Message\ResponseFactoryInterface;
use Cydrickn\SocketIO\Router\Router;
use Cydrickn\SocketIO\Router\RouterProvider;
use Cydrickn\SocketIO\Service\FdFetcher;
use Cydrickn\SocketIO\Storage\Adapter\Rooms;
use Cydrickn\SocketIO\Storage\RoomsInterface;
use Swoole\Http\Request;
use Swoole\Timer;
use Swoole\WebSocket\Frame;
use Swoole\WebSocket\Server as WebSocketServer;

class Server extends Socket
{
    protected const DEFAULT_OPTIONS = [
        'host' => '127.0.0.1',
        'port' => 8000,
        'mode' => 2, // SWOOLE_PROCESS
        'sock_type' => 1, // SWOOLE_SOCK_TCP
        'settings' => [],
    ];

    protected array $config;

    protected array $sockets = [];

    protected SocketManager $socketManager;

    protected PingTimerManager $pingTimerManager;

    public function __construct(array $config, ?Router $router = null, ?RoomsInterface $rooms = null, ?ResponseFactoryInterface $responseFactory = null)
    {
        $this->config = array_replace_recursive(self::DEFAULT_OPTIONS, $config);
        $this->socketManager = new SocketManager();
        $this->pingTimerManager = new PingTimerManager();

        $server = new WebsocketServer($this->config['host'], $this->config['port'], SWOOLE_PROCESS);
        $server->set($this->config['settings']);
        $router = $router ?? new Router();
        $rooms = $rooms ?? new Rooms($server);
        $responseFactory = $responseFactory ?? new ResponseFactory(new FdFetcher($this, $rooms, $this->socketManager));

        parent::__construct($server, $router, $rooms, $responseFactory);

        $this->setEvent();
    }

    public function setServer(WebsocketServer $server, bool $setInitialized = true): void
    {
        $this->server = $server;
    }

    public function setEvent(): void
    {
        $this->server->on('Open', function (WebsocketServer $server, Request $request) {
            $socket = new Socket($this->server, $this->router, $this->rooms, $this->responseFactory);
            $socket->setFd($request->fd);
            $this->socketManager->add($socket);

            $message = json_encode([
                'sid' => $socket->sid,
                'pingInterval' => 25000,
                'pingTimeout' => 20000,
                'upgrades' => [],
            ]);

            $socket->sendTo($socket->getFd(), Type::OPEN->value . $message);
            $this->pingTimerManager->createForSocket($socket);
        });

        $this->server->on('Message', function (WebsocketServer $server, Frame $frame) {
            $socket = $this->socketManager->get($frame->fd);
            if ($socket === null) {
                return;
            }

            $message = MessageRequest::fromFrame($frame, $socket);
            if ($message->getType() === Type::MESSAGE && $message->getMessageType() === MessageType::CONNECT) {
                $socket->sendTo($socket->getFd(), Type::MESSAGE->value . MessageType::CONNECT->value . json_encode(['sid' => $socket->sid]));
                $connectionMessage = new MessageRequest($socket, 'connection', $socket->getFd(), []);
                $this->router->dispatch($connectionMessage);
            } elseif ($message->getType() === Type::PONG) {

            } else {
                $this->router->dispatch($message);
            }
        });

        $this->server->on('Close', function (WebsocketServer $server, int $fd) {
            $socket = $this->socketManager->get($fd);
            if ($socket === null) {
                $socket = new Socket($this->server, $this->router, $this->rooms, $this->responseFactory);
                $socket->setFd($fd);
            }
            $disconnecting = new MessageRequest($socket, 'disconnecting', $fd, []);
            $this->router->dispatch($disconnecting);

            $this->pingTimerManager->remove($fd);
            $this->socketManager->del($fd);

            $disconnect = new MessageRequest($socket, 'disconnect', $fd, []);
            $this->router->dispatch($disconnect);
            unset($socket);
        });
    }

    public function start(): void
    {
        $this->server->start();
    }

    public function getServer(): WebsocketServer
    {
        return $this->server;
    }

    public function getHost(): string
    {
        return $this->server->host;
    }

    public function getPort(): string
    {
        return $this->server->port;
    }

    public function setHost(string $host): void
    {
        $this->config['host'] = $host;
        $this->server->host = $host;
    }

    public function setPort(int $port): void
    {
        $this->config['port'] = $port;
        $this->server->port = $port;
    }

    public function setProvider(RouterProvider $routerProvider): void
    {
        $this->router->setProvider($routerProvider);
    }
}
