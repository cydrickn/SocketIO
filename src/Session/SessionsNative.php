<?php

namespace Cydrickn\SocketIO\Session;

use Cydrickn\SocketIO\Session\Traits\ChannelTrait;
use Swoole\Coroutine\Channel;

class SessionsNative implements SessionStorageInterface
{
    use ChannelTrait;

    public function __construct(private readonly string $pathStorage)
    {
        if (!file_exists($this->pathStorage)) {
            mkdir($this->pathStorage);
        }

        $this->channel = new Channel(10);
    }

    public function get(string $sessionId): array|null
    {
        $filePath = $this->pathStorage . '/' . $sessionId;
        if (!file_exists($filePath)) {
            $this->setData($sessionId, []);
            return [];
        }

        $data = json_decode(file_get_contents($filePath), true);
        if (!is_array($data)) {
            return [];
        }

        return $data;
    }

    protected function setData(string $sessionId, array $data): void
    {
        $filePath = $this->pathStorage . '/' . $sessionId;
        $json = json_encode($data);
        file_put_contents($filePath, $json);
    }

    public function getField(string $sessionId, string $field, mixed $default): mixed
    {
        $data = $this->get($sessionId);

        return $data[$field] ?? $default;
    }

    public function del(string $sessionId): void
    {
        $filePath = $this->pathStorage . '/' . $sessionId;
        if (file_exists($filePath)) {
            unlink($filePath);
        }
    }
}
