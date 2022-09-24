<?php

namespace Cydrickn\SocketIO\Helper;

use Cydrickn\SocketIO\Socket;
use Swoole\Table;

class IdGenerator
{
    private static string $characters = '0123456789abcdefghijklmnopqrstuvwxyz';
    private static string $shuffledChars = '95v86b0ni4latze32gcxoprm71qwhsjdfuky';

    public static function generateFromSocket(Socket $socket, array $addition = []): string
    {
        $info = $socket->getInfo();
        if ($info === false) {
            return '';
        }

        $workerId = $info['worker_id'] ?? 0;
        $socketFd = $info['socket_fd'];
        $remoteIps = explode('.', $info['remote_ip']);
        $serverFd = $info['server_fd'];
        $connectionStrs = str_split((string) $info['connect_time'], 3);
        $shuffleCharSet = str_split(static::$shuffledChars);

        $id = static::getChar($workerId, $shuffleCharSet) . static::random($shuffleCharSet) . $socketFd . static::random($shuffleCharSet);
        foreach ($remoteIps as $remoteIp) {
            $id .= static::getChar(array_sum(str_split($remoteIp)), $shuffleCharSet);
        }
        $id .= static::random($shuffleCharSet) . static::getChar($serverFd, $shuffleCharSet) . static::random($shuffleCharSet, 2);

        foreach ($connectionStrs as $connectionStr) {
            $id .= static::getChar(array_sum(str_split($connectionStr)), $shuffleCharSet);
        }

        $id .= static::random($shuffleCharSet, 2);

        foreach ($addition as $item) {
            if ($item[0] === 'rand') {
                $id .= static::random($shuffleCharSet, $item[1]);
            }
        }

        return $id;
    }

    private static function getChar(int|null $index, array $characters): string
    {
        if ($index === null) {
            $index = 0;
        }

        if ($index >= count($characters)) {
            $index = count($characters) - 1;
        }

        return $characters[$index];
    }

    private static function random(array $characters, int $limit = 1): string
    {
        $str = '';
        for ($i = 0; $i < $limit; $i++) {
            $str .= $characters[rand(0, count($characters) - 1)];
        }

        return $str;
    }
}
