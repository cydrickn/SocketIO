# PHP SocketIO Server
PHP Websocket Server that is compatible with [socket.io](https://socket.io/)

So far the function use in this package is almost same with the naming in socket.io

## Installation

```shell
composer require cydrickn/socketio
```

Then in your main php code add

```php
require __DIR__ . '/../vendor/autoload.php';
```

## Usage

### Initializing your server

To initialize 

```php
$server = new \Cydrickn\SocketIO\Server([
    'host' => '0.0.0.0',
    'port' => 8000,
    'mode' => SWOOLE_PROCESS,
    'settings' => [
        \Swoole\Constant::OPTION_WORKER_NUM => swoole_cpu_num() * 2,
        \Swoole\Constant::OPTION_ENABLE_STATIC_HANDLER => true,
    ]
]);

$server->on('Started', function (\Cydrickn\SocketIO\Server $server) {
    echo 'Websocket is now listening in ' . $server->getHost() . ':' . $server->getPort() . PHP_EOL;
});

$server->on('connection', function (\Cydrickn\SocketIO\Socket $socket) {
    // ...
});

$server->start();
```

### Server Options

| Key        | Details                                                                                                         | Default             |
|------------|-----------------------------------------------------------------------------------------------------------------|---------------------|
| host       | The host of the server                                                                                          | 127.0.0.1           |
| port       | The port of the server                                                                                          | 8000                |
| mode       | The mode for server                                                                                             | 2 / SWOOLE_PROCESS  |
| sock_type  | The socket type for server                                                                                      | 1 / SWOOLE_SOCK_TCP |
| settings   | The setting is base on [swoole configuration ](https://openswoole.com/docs/modules/swoole-server/configuration) | [] / Empty Array    |

## Server Instance

This is the server [\Cydrickn\SocketIO\Server](src/Server.php), it also inherited all methods of [\Cydrickn\SocketIO\Socket](src/Socket.php)

## Events

### Basic Emit

To emit, just need to call the `\Cydrickn\SocketIO\Socket::emit`

```php
$server->on('connection', function (\Cydrickn\SocketIO\Socket $socket) {
    $socket->emit('hello', 'world');
});
```

You can also pass as many as you want for the parameters

```php
$socket->emit('hello', 'world', 1, 2, 3, 'more');
```

There is no need to run json_encode on objects/arrays as it will be done for you.

```php
// BAD
$socket->emit('hi', json_encode(['name' => 'Juan']));

// GOOD
$socket->emit('hi', ['name' => 'Juan']);
```

### Broadcasting

Broadcast is like just the simple emit, but it will send to all connected client except the current client

```php
$socket->broadcast()->emit('hi', ['name' => 'Juan']);
```

### Sending to all clients

The toAll will emit a message to all connected clients including the current client

```php
$socket->toAll()->emit('hi', ['name' => 'Juan']);
```

### Listening

To listen to any event

```php
$server->on('hello', function (\Cydrickn\SocketIO\Socket $socket, string $world) {
    // ...
});

$server->on('hi', function (\Cydrickn\SocketIO\Socket $socket, array $name) {
    // ...
});
```

## Server Event

This event can't be use for the route
Since this event are Swoole Websocket Event
All Event with * should not be used

- Request - When you use it for http
- *WorkerStart - You must not replace this event since this is the worker start logic for this package
- *Start - You must not replace this event since this is the start logic for this package
- *Open - You must not replace this event since this is the connection logic for this package
- *Message - You must not replace this event since this is the message logic for this package
- *Close - You must not replace this event since this is the message logic for this package

## Rooms

In this package it was already included the rooms

To join to group just call the function `join`

```php
$socket->join('room1');
```

To emit a message

```php
$socket->to('room1')->emit('hi', ['name' => 'Juan']);
```

Emit to multiple room

```php
$socket->to('room1')->to('room2')->emit('hi', ['name' => 'Juan']);
```

Leaving the room

```php
$socket->leave('room1');
```

Sending to specific user

In socket.io javascript, the user was automatically created a new room for each client sid.

But currently in this package it will not create a new room for each client.

In this package you just need to specify if its a room or a sid

```php
use Cydrickn\SocketIO\Message\Response;

$socket->on('private message', (\Cydrickn\SocketIO\Socket $socket, $anotherSocketId, $msg) => {
    $socket->($anotherSocketId, Response::TO_TYPE_SID).emit('private message', $socket->sid, $msg);
});
```

## Middleware

You can add an middleware for the server

```php
$server->on(function (\Cydrickn\SocketIO\Socket $socket, callable $next) {
    // ...
    $next();
});
```

To not continue the connection you just pass \Error in the $next
```php
$server->on(function (\Cydrickn\SocketIO\Socket $socket, callable $next) {
    // ...
    $next(new \Error('Something went wrong'));
});
```

You can also add middleware for handshake event of Swoole Server.
Just passed true to the second argument.

Also in callback it will pass the response for you to modify if you need it
```php
$server->on(function (\Cydrickn\SocketIO\Socket $socket, \Swoole\Http\Response $response, callable $next) {
    // ...
}, true);
```

Example of middleware that use in handshake is the [Cydrickn\SocketIO\Middleware\CookieSessionMiddleware](/src/Middleware/CookieSessionMiddleware.php).
This middleware will create a session that uses the cookie and if the client did not send the session cookie then it will
create a cookie and response it from the handshake.

## Session

In this package the there is already session storage that you can use,

- **SessionsTable** - Uses the Swoole\Table as the storage
- **SessionsNative** - Uses the file storage

Using Swoole, session_start, $_SESSION should not be use since this function are global it stores the data
in the process itself.

The session that provided here does not use this predefined session extensions.
Currently, the session is define per connection so you can take the session via.

```php
$socket->getSession();
```

This getSession can return null if you don't have any middleware that creating the session.

To set a session
```php
$session = $server->getSessionStorage()->get('123456'); // This will automatically created once it does not exists
$socket->setSession($session);
```

You can also customize your session storage, just implement the
[Cydrickn\SocketIO\Session\SessionStorageInterface](src/Session/SessionStorageInterface.php)

```php
<?php

class CustomeStorage implements SessionStorageInterface {
    // ...
}
```

After creating your storage

You need to pass this in your server constructor

```php
$server = new \Cydrickn\SocketIO\Server([
    'host' => '0.0.0.0',
    'port' => 8000,
    'mode' => SWOOLE_PROCESS,
    'serve_http' => true,
    'settings' => [
        \Swoole\Constant::OPTION_WORKER_NUM => swoole_cpu_num() * 2,
        \Swoole\Constant::OPTION_ENABLE_STATIC_HANDLER => true,
        \Swoole\Constant::OPTION_DOCUMENT_ROOT => dirname(__DIR__).'/examples'
    ]
], sessionStorage: new CustomeStorage());
```

## Example

- [Chat Example](https://github.com/cydrickn/SocketIO/tree/main/examples)

## TODO

- [X] Leaving room
- [X] Fix disconnection event
- [X] [Emit Acknowledgement](https://socket.io/docs/v4/emitting-events/#acknowledgements)
- [ ] [Implement with timeout emit](https://socket.io/docs/v4/emitting-events/#with-timeout)
- [ ] [Implement Catch all listeners](https://socket.io/docs/v4/listening-to-events/#catch-all-listeners)
- [ ] [Implement once, off, removeAllListeners](https://socket.io/docs/v4/listening-to-events/#eventemitter-methods)
