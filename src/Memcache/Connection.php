<?php

namespace Fiber\Memcache;

use Fiber\Helper as f;

class Connection
{
    use \Fiber\Util\LazySocket;

    const REPLIES = [
        'CLIENT_ERROR' => null,
        'DELETED'      => true,
        'ERROR'        => false,
        'ERROR'        => null,
        'EXISTS'       => false,
        'NOT_FOUND'    => false,
        'NOT_STORED'   => false,
        'OK'           => true,
        'SERVER_ERROR' => null,
        'STORED'       => true,
    ];

    public function __construct(string $host, int $port = 11211)
    {
        $this->server = "tcp://$host:$port";
    }

    public function get($key, $ext = false)
    {
        $key = (array) $key;

        $keys = implode(' ', $key);
        f\write($this->getSocket(), "get $keys\r\n");

        $reply = f\find($this->getSocket(), "\r\nEND\r\n");

        $lines = explode("\r\n", $reply);

        $values = [];
        for ($i = 0; $i < count($lines); $i = $i + 2) {
            $words = explode(' ', $lines[$i]);
            $key = $words[1];
            $value = $lines[$i + 1];

            $values[$key] = !$ext ? $value : [
                'key'   => $key,
                'value' => $value,
                'flags' => $words[2],
                'cas'   => $words[4],
            ];
        }

        if (count($values) === 1) {
            return current($values);
        }

        return $values;
    }

    public function set($key, $value, $exptime = 0, $flags = 0)
    {
        return $this->query("set $key $flags $exptime " . strlen($value), $value);
    }

    public function add($key, $value, $exptime = 0, $flags = 0)
    {
        return $this->query("add $key $flags $exptime " . strlen($value), $value);
    }

    public function replace($key, $value, $exptime = 0, $flags = 0)
    {
        return $this->query("replace $key $flags $exptime " . strlen($value), $value);
    }

    public function append($key, $value)
    {
        return $this->query("append $key 0 0 " . strlen($value), $value);
    }

    public function prepend($key, $value)
    {
        return $this->query("prepend $key 0 0 " . strlen($value), $value);
    }

    public function cas($key, $value, $cas, $exptime = 0, $flags = 0)
    {
        return $this->query("cas $key $flags $exptime " . strlen($value) . " $cas", $value);
    }

    public function del($key)
    {
        return $this->query("delete $key");
    }

    public function incr($key, $value = 1)
    {
        $result = $this->query("incr $key $value");
        if ($result) {
            return $result[0];
        }

        return $result;
    }

    public function decr($key, $value = 1)
    {
        $result = $this->query("decr $key $value");
        if ($result) {
            return $result[0];
        }

        return $result;
    }

    public function flushAll($exptime = 0)
    {
        return $this->query("flush_all $exptime");
    }

    public function query()
    {
        $cmd = implode("\r\n", func_get_args())."\r\n";

        f\write($this->getSocket(), $cmd);

        return $this->readLine();
    }

    private function readLine()
    {
        $reply = f\find($this->getSocket(), "\r\n");

        $words = explode(' ', $reply);
        $result = array_key_exists($words[0], self::REPLIES) ? self::REPLIES[$words[0]] : $words;

        if (is_null($result)) {
            throw new Exception($reply);
        }

        return $result;
    }
}
