<?php

namespace Cydrickn\SocketIO\Room;

interface RoomsInterface
{
    public function add(string $roomName): void;

    public function remove(string $roomName): void;

    public function join(string $roomName, int $fd): void;

    public function leave(string $roomName, int $fd): void;

    public function getFds(string $roomName): array;

    public function getFdRooms(int $fd): array;

    public function start(): void;

    public function stop(): void;

    public function count(): int;

    public function on(string $event, callable $callback): void;
}
