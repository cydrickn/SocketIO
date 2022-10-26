<?php

namespace Cydrickn\SocketIO\Message;

use Cydrickn\SocketIO\Manager\AckManager;
use Cydrickn\SocketIO\Manager\SocketManager;
use Cydrickn\SocketIO\Service\FdFetcher;
use Cydrickn\SocketIO\Socket;
use Cydrickn\SocketIO\Table\Timer;

class ResponseFactory implements ResponseFactoryInterface
{
    public function __construct(private FdFetcher $fdFetcher, private SocketManager $socketManager, private AckManager $ackManager, private Timer $timer)
    {
    }

    public function create(Socket $socket): Response
    {
        return Response::new($socket, $this->fdFetcher, $this->ackManager, $this->socketManager, $this->timer, $socket->getFd());
    }
}
