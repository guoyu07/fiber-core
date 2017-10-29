<?php

namespace Fiber\Redis;

use Fiber\Helper as f;

class Connection
{
    private $server;
    private $socket;

    public function __construct(string $host, int $port = 6379)
    {
        $this->server = "tcp://$host:$port";
    }

    private function getSocket()
    {
        if (!$this->socket) {
            $this->socket = f\connect($this->server);
        }

        return $this->socket;
    }

    public function __call($method, array $args)
    {
        array_unshift($args, $method);
        $cmd = '*' . count($args) . "\r\n";
        foreach ($args as $item) {
            $cmd .= '$' . strlen($item) . "\r\n" . $item . "\r\n";
        }

        f\write($this->getSocket(), $cmd);

        return $this->parseResponse();
    }

    private function parseResponse()
    {
        $line = f\find($this->getSocket(), "\r\n");
        $type = $line[0];
        $result = substr($line, 1);

        if ($type == '-') { // error message
            throw new Exception($result);
        } elseif ($type == '$') { // bulk reply
            if ($result == -1) {
                $result = null;
            } else {
                $line = f\read($this->getSocket(), $result + 2);
                $result = substr($line, 0, strlen($line) - 2);
            }
        } elseif ($type == '*') { // multi-bulk reply
            $count = (int) $result;
            for ($i = 0, $result = []; $i < $count; $i++) {
                $result[] = $this->parseResponse();
            }
        }

        return $result;
    }
}
