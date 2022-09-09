<?php

namespace Cydrickn\SocketIO\Enum;

enum Type: int
{
    case NONE = -1;
    case OPEN = 0;
    case CLOSE = 1;
    case PING = 2;
    case PONG = 3;
    case MESSAGE = 4;
    case UPGRADE = 5;
    case NOOP = 6;
}
