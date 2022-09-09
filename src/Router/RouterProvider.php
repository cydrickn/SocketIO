<?php

namespace Cydrickn\SocketIO\Router;

class RouterProvider
{
    private array $routes = [];

    public function addRoute(Route $route): self
    {
        if ($this->hasRoute($route)) {
            throw new ExistsRouteException('The path for ' . $route->getPath() . ' is already exists.');
        }

        $this->routes[$route->getPath()] = $route;

        return $this;
    }

    public function hasRoute(Route|string $path): bool
    {
        if ($path instanceof Route) {
            $path = $path->getPath();
        }

        return array_key_exists($path, $this->routes);
    }

    public function getRoute(string $path): ?Route
    {
        if (array_key_exists($path, $this->routes)) {
            return $this->routes[$path];
        }

        return null;
    }

    public function removeRoute(string $path): void
    {
        if ($this->hasRoute($path)) {
            unset($this->routes[$path]);
        }
    }
}
