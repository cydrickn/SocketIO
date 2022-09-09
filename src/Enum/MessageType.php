<?php

namespace Cydrickn\SocketIO\Enum;

enum MessageType: int
{
    case CONNECT = 0;
    case DISCONNECT = 1;
    case EVENT = 2;
    case ACK = 3;
    case ERROR = 4;
    case BINARY_EVENT = 5;
    case BINARY_ACK = 6;
}
