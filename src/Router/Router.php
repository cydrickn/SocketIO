<?php

namespace Cydrickn\SocketIO\Router;

use Cydrickn\SocketIO\Message\Request;

class Router
{
    private RouterProvider $routerProvider;

    public function __construct(?RouterProvider $routerProvider = null)
    {
        $this->routerProvider = $routerProvider ?? new RouterProvider();
    }

    public function setProvider(RouterProvider $routerProvider): void
    {
        $this->routerProvider = $routerProvider;
    }

    public function addRoute(string $path, callable $callback): void
    {
        $route = new Route($callback, $path);
        if (!$this->routerProvider->hasRoute($route)) {
            $this->routerProvider->addRoute($route);
        }
    }

    public function dispatch(Request $request): Request
    {
        $route = $this->routerProvider->getRoute($request->getPath());
        if ($route instanceof Route) {
            $callback = $route->getCallback();
            call_user_func_array($callback, [$request->getSocket(), ...$request->getData()]);
        }

        return $request;
    }
}
