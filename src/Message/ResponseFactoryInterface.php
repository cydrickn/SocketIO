<?php

namespace Cydrickn\SocketIO\Message;

use Cydrickn\SocketIO\Socket;

interface ResponseFactoryInterface
{
    public function create(Socket $socket): Response;
}
