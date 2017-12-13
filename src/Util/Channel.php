<?php
namespace Fiber\Util;

use Fiber\Helper as f;

class Channel
{
    private $sockets;
    private $data;

    public function __construct()
    {
        socket_create_pair(AF_UNIX, SOCK_DGRAM, 0, $sockets);

        socket_set_nonblock($sockets[0]);
        socket_set_nonblock($sockets[1]);

        $this->sockets = $sockets;
    }

    public function write($data)
    {
        $this->data = $data;

        f\write($this->sockets[1], '+');
        f\read0($this->sockets[1], 1);
    }

    public function read()
    {
        f\read0($this->sockets[0], 1);
        f\write($this->sockets[0], '-');

        return $this->data;
    }

    public function __destruct()
    {
        socket_close($this->sockets[0]);
        socket_close($this->sockets[1]);
    }
}
