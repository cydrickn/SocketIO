<?php

namespace Cydrickn\SocketIO;

use Cydrickn\SocketIO\Enum\MessageType;
use Cydrickn\SocketIO\Enum\Type;
use Cydrickn\SocketIO\Manager\AckManager;
use Cydrickn\SocketIO\Manager\PingTimerManager;
use Cydrickn\SocketIO\Manager\SocketManager;
use Cydrickn\SocketIO\Message\Request as MessageRequest;
use Cydrickn\SocketIO\Message\ResponseFactory;
use Cydrickn\SocketIO\Message\ResponseFactoryInterface;
use Cydrickn\SocketIO\Middleware\HandshakeMiddlewareInterface;
use Cydrickn\SocketIO\Middleware\InvokableMiddlewareInterface;
use Cydrickn\SocketIO\Middleware\MiddlewareInterface;
use Cydrickn\SocketIO\Room\RoomsInterface;
use Cydrickn\SocketIO\Room\RoomsTable;
use Cydrickn\SocketIO\Router\Router;
use Cydrickn\SocketIO\Router\RouterProvider;
use Cydrickn\SocketIO\Service\FdFetcher;
use Cydrickn\SocketIO\Session\SessionsTable;
use Cydrickn\SocketIO\Session\SessionStorageInterface;
use Cydrickn\SocketIO\Table\Timer;
use Swoole\Constant;
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

    protected ?SocketManager $socketManager = null;

    protected ?PingTimerManager $pingTimerManager = null;

    protected ?SessionStorageInterface $sessionStorage = null;

    protected AckManager $ackManager;

    protected Timer $timer;

    protected array $middlewares = [];
    protected array $handShakeMiddleware = [];

    protected array $serverEvents;

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
        $this->timer = new Timer();

        $server = new WebsocketServer($this->config['host'], $this->config['port'], SWOOLE_PROCESS);
        $setting = [
            ...$this->config['settings'],
            Constant::OPTION_OPEN_WEBSOCKET_PONG_FRAME => true,
            Constant::OPTION_OPEN_WEBSOCKET_PING_FRAME => true,
        ];
        $server->set($setting);
        $router = $router ?? new Router();
        $rooms = $rooms ?? new RoomsTable();
        $this->ackManager = new AckManager();
        $responseFactory = $responseFactory ?? new ResponseFactory(
            new FdFetcher($this, $rooms, $this->socketManager),
            $this->socketManager,
            $this->ackManager,
            $this->timer
        );

        $this->serverEvents = [
            'Start' => [$this, 'onStart'],
            'WorkerStart' => [$this, 'onWorkerStart'],
            'Open' => [$this, 'onOpen'],
            'Message' => [$this, 'onMessage'],
            'Close' => [$this, 'onClose'],
            'handshake' => [$this, 'onHandshake'],
            'BeforeReload' => [$this, 'onBeforeReload'],
            'WorkerExit' => [$this, 'onWorkerExit'],
            'PipeMessage' => [$this, 'onPipeMessage'],
        ];

        parent::__construct($server, $router, $rooms, $responseFactory);
    }

    public function setSessionStorage(SessionStorageInterface $sessionStorage)
    {
        $this->sessionStorage = $sessionStorage;
    }

    public function onStart()
    {
        $message = new MessageRequest($this, 'Started', self::SYSTEM_FD, []);
        $this->router->dispatch($message);
    }

    public function onWorkerStart()
    {
        $this->sessionStorage->start();
        $this->rooms->start();

        $message = new MessageRequest($this, 'WorkerStarted', self::SYSTEM_FD, []);
        $this->router->dispatch($message);
    }

    public function onWorkerExit()
    {
        $this->sessionStorage->stop();
        $this->rooms->stop();

        \Swoole\Timer::clearAll();
    }

    public function onBeforeReload()
    {
        // Do nothing
    }

    public function onHandshake(Request $request, Response $response)
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
            $callback = $middleware[0];
            if ($callback instanceof MiddlewareInterface) {
                $callback = [$middleware[0], 'handle'];
            }

            call_user_func($callback, $socket, $response, function (?\Error $error = null)  use ($socket, &$continue) {
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
    }

    public function onOpen(WebsocketServer $server, Socket $socket) {
        if (!$server->isEstablished($socket->getFd())) {
            return;
        }

        $message = json_encode([
            'sid' => $socket->sid,
            'pingInterval' => $socket->pingInterval,
            'pingTimeout' => $socket->pingTimeout,
            'upgrades' => [],
        ]);

        $socket->sendTo($socket->getFd(), Type::OPEN->value . $message);
        $this->pingTimerManager->createForSocket($socket);
    }

    public function onMessage(WebsocketServer $server, Frame $frame) {
        $socket = $this->socketManager->get($frame->fd);
        if ($socket === null) {
            return;
        }

        if (!$server->isEstablished($socket->getFd())) {
            return;
        }

        $message = MessageRequest::fromFrame($frame, $socket);
        if ($message->getType() === Type::MESSAGE && $message->getMessageType() === MessageType::CONNECT) {
            $continue = true;
            foreach ($this->middlewares as $middleware) {
                call_user_func([$middleware[0], 'handle'], $socket, function (?\Error $error = null)  use ($socket, &$continue) {
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
            $this->pingTimerManager->removeTimeout($frame->fd);
        } elseif ($message->getType() === Type::MESSAGE && $message->getMessageType() === MessageType::ACK) {
            if (!$this->ackManager->has($message->getFd() . '::' . $message->getCallbackNum())) {
                for ($i = 0; $i < $this->config['settings'][Constant::OPTION_WORKER_NUM]; $i++) {
                    if ($this->server->getWorkerId() === $i) {
                        continue;
                    }

                    $this->server->sendMessage(json_encode([
                        'data' => $message->getData(),
                        'type' => $message->getType()->value,
                        'messageType' => $message->getMessageType()->value,
                        'fd' => $message->getFd(),
                        'key' => $message->getFd() . '::' . $message->getCallbackNum(),
                    ]), $i);
                }
                return;
            }

            $ackId = $message->getFd() . '::' . $message->getCallbackNum();
            $ackCallback = $this->ackManager->get($ackId);
            if ($ackCallback['timeout'] > 0) {
                $this->timer->clear('ack::' . $ackCallback['group']);
                call_user_func($ackCallback['callback'], true, ...$message->getData());
            } else {
                call_user_func($ackCallback['callback'], ...$message->getData());
            }
        } else {
            $this->router->dispatch($message);
        }
    }

    public function onPipeMessage(WebsocketServer $server, int $workerId, mixed $data)
    {
        $message = json_decode($data, true);

        if (!($message['type'] === Type::MESSAGE->value && $message['messageType'] === MessageType::ACK->value)) {
            return;
        }

        if (!$this->ackManager->has($message['key'])) {
            return;
        }

        $ackCallback = $this->ackManager->get($message['key']);

        if ($ackCallback['timeout'] > 0) {
            $this->timer->clear('ack::' . $ackCallback['group']);
            call_user_func($ackCallback['callback'], true, ...$message['data']);
        } else {
            call_user_func($ackCallback['callback'], ...$message['data']);
        }
    }

    public function onClose(WebsocketServer $server, int $fd) {
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
    }

    public function setEvent(): void
    {
        foreach ($this->serverEvents as $serverEvent => $function) {
            $this->server->on($serverEvent, $function);
        }
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

    public function use(MiddlewareInterface|callable $middleware, ?bool $handshake = null, ?int $priority = null): void
    {
        if ($priority === null && $middleware instanceof MiddlewareInterface) {
            $priority = $middleware->getPriority();
        } elseif ($priority === null) {
            $priority = 500;
        }

        if ($handshake === null && $middleware instanceof HandshakeMiddlewareInterface) {
            $handshake = true;
        } elseif ($handshake === null) {
            $handshake = false;
        }

        if ($handshake) {
            $this->handShakeMiddleware[] = [$middleware, $priority];
            usort($this->handShakeMiddleware, function ($a, $b) {
                if ($a[1] === $b[1]) {
                    return 0;
                }

                return $a[1] < $b[1]? 1 : 0;
            });

            return;
        }

        $this->middlewares[] = [$middleware, $priority];
        usort($this->middlewares, function ($a, $b) {
            if ($a[1] === $b[1]) {
                return 0;
            }

            return $a[1] < $b[1]? 1 : 0;
        });
    }

    public function getSessionStorage(): SessionStorageInterface
    {
        return $this->sessionStorage;
    }

    public function removeSystemEvents(string $name): void
    {
        unset($this->serverEvents, $name);
    }

    public function setSystemEvents(string $name, callable $callable): void
    {
        $this->serverEvents[$name] = $callable;
    }

    public function getTimer(): Timer
    {
        return $this->timer;
    }

    public function onRoomEvent(string $event, callable $callback): void
    {
        $this->rooms->on($event, $callback);
    }
}
