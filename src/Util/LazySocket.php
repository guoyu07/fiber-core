<?php

namespace Fiber\Util;

use Fiber\Helper as f;

trait LazySocket
{
    private $server;

    private $socket;

    private function getSocket()
    {
        if (!$this->socket) {
            $this->socket = f\connect($this->server);
        }

        return $this->socket;
    }
}
