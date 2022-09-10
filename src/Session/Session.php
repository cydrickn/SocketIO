<?php

namespace Cydrickn\SocketIO\Session;

class Session
{
    protected bool $autoCommit = true;

    public function __construct(private SessionStorageInterface $sessionStorage, private string $id, protected array $data = [])
    {
    }

    public function __get(string $name)
    {
        return $this->get($name);
    }

    public function __set(string $name, mixed $data)
    {
        $this->set($name, $data);
    }

    public function get(string $name): mixed
    {
        return $this->data[$name] ?? null;
    }

    public function set(string $name, mixed $data)
    {
        $this->data[$name] = $data;
        if ($this->autoCommit) {
            $this->commit();
        }
    }

    public function commit(): void
    {
        $this->sessionStorage->set($this->id, $this->data);
    }
}
