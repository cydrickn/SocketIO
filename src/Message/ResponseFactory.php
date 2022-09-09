<?php

namespace Cydrickn\SocketIO\Message;

use Cydrickn\SocketIO\Service\FdFetcher;
use Cydrickn\SocketIO\Socket;

class ResponseFactory implements ResponseFactoryInterface
{
    public function __construct(private FdFetcher $fdFetcher)
    {
    }

    public function create(Socket $socket): Response
    {
        $response = Response::new($socket, $this->fdFetcher, $socket->getFd());

        return $response;
    }
}
