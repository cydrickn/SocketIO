<?php

namespace Cydrickn\SocketIO\Session;

interface SessionStorageInterface
{
    public function start(): void;

    public function get(string $sessionId): array|null;

    public function getField(string $sessionId, string $field, mixed $default): mixed;

    public function set(string $sessionId, array $data): void;

    public function setField(string $sessionId, string $field, mixed $data): void;

    public function del(string $sessionId): void;

    public function delField(string $sessionId, string $field): void;

    public function getSession(string $sessionId): Session;
}
