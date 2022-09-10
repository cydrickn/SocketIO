<?php

namespace Cydrickn\SocketIO;

use Cydrickn\SocketIO\Enum\MessageType;
use Cydrickn\SocketIO\Enum\Type;
use Cydrickn\SocketIO\Manager\PingTimerManager;
use Cydrickn\SocketIO\Manager\SocketManager;
use Cydrickn\SocketIO\Message\Request as MessageRequest;
use Cydrickn\SocketIO\Message\ResponseFactory;
use Cydrickn\SocketIO\Message\ResponseFactoryInterface;
use Cydrickn\SocketIO\Room\RoomsInterface;
use Cydrickn\SocketIO\Room\RoomsTable;
use Cydrickn\SocketIO\Router\Router;
use Cydrickn\SocketIO\Router\RouterProvider;
use Cydrickn\SocketIO\Service\FdFetcher;
use Cydrickn\SocketIO\Session\SessionsTable;
use Cydrickn\SocketIO\Session\SessionStorageInterface;
use Swoole\Http\Request;
use Swoole\Http\Response;
use Swoole\Table;
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

    protected SessionStorageInterface $sessionStorage;

    protected array $middlewares = [];
    protected array $handShakeMiddleware = [];

    public function __construct(
        array $config,
        ?Router $router = null,
        ?RoomsInterface $rooms = null,
        ?ResponseFactoryInterface $responseFactory = null,
        ?SessionStorageInterface $sessionStorage = null
    ) {
        $this->config = array_replace_recursive(self::DEFAULT_OPTIONS, $config);
        $this->socketManager = new SocketManager();
        $this->pingTimerManager = new PingTimerManager();
        $this->sessionStorage = $sessionStorage ?? new SessionsTable([['fd', Table::TYPE_INT], ['sid', Table::TYPE_STRING, 64]]);

        $server = new WebsocketServer($this->config['host'], $this->config['port'], SWOOLE_PROCESS);
        $server->set($this->config['settings']);
        $router = $router ?? new Router();
        $rooms = $rooms ?? new RoomsTable();
        $responseFactory = $responseFactory ?? new ResponseFactory(new FdFetcher($this, $rooms, $this->socketManager));

        parent::__construct($server, $router, $rooms, $responseFactory);
    }

    public function setEvent(): void
    {
        $this->server->on('Start', function () {
            $message = new MessageRequest($this, 'Started', self::SYSTEM_FD, []);
            $this->router->dispatch($message);
        });

        $this->server->on('WorkerStart', function () {
            $this->sessionStorage->start();
            $this->rooms->start();
            $message = new MessageRequest($this, 'WorkerStarted', self::SYSTEM_FD, []);
            $this->router->dispatch($message);
        });

        $this->server->on('handshake', function (Request $request, Response $response)
        {
            $secWebSocketKey = $request->header['sec-websocket-key'];
            $patten = '#^[+/0-9A-Za-z]{21}[AQgw]==$#';

            // At this stage if the socket request does not meet custom requirements, you can ->end() it here and return false...

            // Websocket handshake connection algorithm verification
            if (0 === preg_match($patten, $secWebSocketKey) || 16 !== strlen(base64_decode($secWebSocketKey)))
            {
                $response->end();
                return false;
            }

            $key = base64_encode(sha1($request->header['sec-websocket-key'] . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));

            $headers = [
                'Upgrade' => 'websocket',
                'Connection' => 'Upgrade',
                'Sec-WebSocket-Accept' => $key,
                'Sec-WebSocket-Version' => '13',
            ];

            if(isset($request->header['sec-websocket-protocol']))
            {
                $headers['Sec-WebSocket-Protocol'] = $request->header['sec-websocket-protocol'];
            }

            foreach($headers as $key => $val)
            {
                $response->header($key, $val);
            }

            $socket = new Socket($this->server, $this->router, $this->rooms, $this->responseFactory);
            $socket->setFd($request->fd);
            $socket->setRequest($request);

            $continue = true;
            foreach ($this->handShakeMiddleware as $middleware) {
                call_user_func($middleware, $socket, $response, function (?\Error $error = null)  use ($socket, &$continue) {
                    if ($error) {
                        $continue = false;
                    }
                });

                if (!$continue) {
                    break;
                }
            }

            if (!$continue) {
                $response->status(400);
                $response->end();

                return false;
            }

            $response->status(101);
            $response->end();

            $this->server->defer(function () use ($socket) {
                $this->socketManager->add($socket);
                call_user_func($this->server->getCallback('Open'), $this->server, $socket);
            });

            return true;
        });

        $this->server->on('Open', function (WebsocketServer $server, Socket $socket) {
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
                $continue = true;
                foreach ($this->middlewares as $middleware) {
                    call_user_func($middleware, $socket, function (?\Error $error = null)  use ($socket, &$continue) {
                        if ($error) {
                            $socket->sendTo($socket->getFd(), Type::MESSAGE->value . MessageType::ERROR->value . json_encode(['message' => $error->getMessage()]));
                            $continue = false;
                        }
                    });

                    if (!$continue) {
                        break;
                    }
                }
                if ($continue) {
                    $socket->sendTo($socket->getFd(), Type::MESSAGE->value . MessageType::CONNECT->value . json_encode(['sid' => $socket->sid]));
                    $connectionMessage = new MessageRequest($socket, 'connection', $socket->getFd(), []);
                    $this->router->dispatch($connectionMessage);
                }
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
            $rooms = $this->rooms->getFdRooms($fd);
            foreach ($rooms as $room) {
                $this->rooms->leave($room, $fd);
            }

            $disconnect = new MessageRequest($socket, 'disconnect', $fd, []);
            $this->router->dispatch($disconnect);
            unset($socket);
        });
    }

    public function start(): void
    {
        $this->setEvent();
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

    public function use(callable $middleware, bool $isHandshake = false): void
    {
        if ($isHandshake) {
            $this->handShakeMiddleware[] = $middleware;
            return;
        }

        $this->middlewares[] = $middleware;
    }

    public function getSessionStorage(): SessionStorageInterface
    {
        return $this->sessionStorage;
    }
}
