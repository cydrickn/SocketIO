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

- Start - When server starts
- WorkerStart - When worker starts
- Request - When you use it for http
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

## Example

- [Chat Example](https://github.com/cydrickn/SocketIO/tree/main/examples)

## TODO

- [ ] Leaving room
- [ ] Fix disconnection event
- [ ] Add route middleware
- [ ] [Emit Acknowledgement](https://socket.io/docs/v4/emitting-events/#acknowledgements)
- [ ] [Implement with timeout emit](https://socket.io/docs/v4/emitting-events/#with-timeout)
- [ ] [Implement Catch all listeners](https://socket.io/docs/v4/listening-to-events/#catch-all-listeners)
- [ ] [Implement once, off, removeAllListeners](https://socket.io/docs/v4/listening-to-events/#eventemitter-methods)
