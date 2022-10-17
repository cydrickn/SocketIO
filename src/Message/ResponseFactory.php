<?php

namespace Cydrickn\SocketIO\Message;

use Cydrickn\SocketIO\Manager\AckManager;
use Cydrickn\SocketIO\Manager\SocketManager;
use Cydrickn\SocketIO\Service\FdFetcher;
use Cydrickn\SocketIO\Socket;

class ResponseFactory implements ResponseFactoryInterface
{
    public function __construct(private FdFetcher $fdFetcher, private SocketManager $socketManager, private AckManager $ackManager)
    {
    }

    public function create(Socket $socket): Response
    {
        $response = Response::new($socket, $this->fdFetcher, $this->ackManager, $this->socketManager, $socket->getFd());

        return $response;
    }
}
