<?php

namespace Cydrickn\SocketIO\Service;

use Cydrickn\SocketIO\Manager\SocketManager;
use Cydrickn\SocketIO\Message\Response;
use Cydrickn\SocketIO\Room\RoomsInterface;
use Cydrickn\SocketIO\Server;

class FdFetcher
{
    public function __construct(private Server $server, private RoomsInterface $rooms, private SocketManager $socketManager)
    {

    }

    public function find(int|string $fd, int $type): array
    {

        return match ($type) {
            Response::TO_TYPE_FD => [$fd],
            Response::TO_TYPE_SID => $this->findBySid($fd),
            Response::TO_TYPE_ROOM => $this->findByRoom($fd),
            default => [],
        };
    }

    protected function findByRoom(string|int $room): array
    {
        return $this->rooms->getFds($room);
    }

    protected function findBySid(string|int $sid): array
    {
        $fd = $this->socketManager->getFdBySid($sid);
        if ($fd === null) {
            return [];
        }

        return [$fd];
    }
}
