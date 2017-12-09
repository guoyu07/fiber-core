<?php

namespace Fiber\Util;

use Fiber\Helper as f;

trait LazySocket
{
    private $server;

    private $socket;

    private function getSocket()
    {
        if ($this->socket) {
            return $this->socket;
        }

        $socket = f\connect($this->server);
        if (!$socket) {
            throw new \RuntimeException("Connect to $this->server failed");
        }

        $this->socket = $socket;

        return $socket;
    }
}
