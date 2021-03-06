<?php
namespace Fiber\Helper;

const AWAIT_READ_AT_MOST = 4;
const AWAIT_READ_BY_LENGTH = 0;
const AWAIT_READ_BY_STOP = 3;
const AWAIT_WRITE_ALL = 1;
const AWAIT_CONNECT = 5;
const AWAIT_SLEEP = 2;

use Amp\Loop;
use LibDNS\Records\ResourceQTypes;
use Fiber\Dns\BasicResolver;

function connect(string $uri, int $timeout_ms = 0)
{
    $uri = parse_url($uri);

    if (!$uri) {
        throw new \InvalidArgumentException("invalid uri: $uri");
    }

    if ($uri['scheme'] !== 'tcp' && $uri['scheme'] !== 'http') {
        throw new \UnexpectedValueException("unsupported schema: ".$uri['scheme']);
    }

    if (!$uri['port'] && $uri['schema'] !== 'http') {
        $port = 80;
    } else {
        $port = (int) $uri['port'];
    }

    if (is_numeric($uri['host']['0'])) {
        $ip = $uri['host'];
        if (!@\inet_pton($ip)) {
            throw new \InvalidArgumentException("invalid ip: $ip");
        }
    } else {
        $domain =$uri['host'];
        $ips = dig($domain);
        if (!$ips) {
            throw new \InvalidArgumentException("invalid domain: $domain");
        }
        $ip = $ips[0]['ip'];
    }

    $fd = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
    if ($fd === false) {
        return false;
    }

    if (!socket_set_nonblock($fd)) {
        socket_close($fd);
        return false;
    }

    $status = socket_connect($fd, $ip, $port);

    if ($status === true) {
        return $fd;
    }

    if ($status === false && socket_last_error($fd) !== 115 /* EINPROGRESS */) {
        socket_close($fd);
        return false;
    }

    $status = \Fiber::yield([AWAIT_CONNECT, $fd, null, $timeout_ms]);
    if ($status) {
        return $fd;
    }

    return 0;
}

function listen(string $uri, $func = null, int $backlog = 0)
{
    $uri = parse_url($uri);

    if (!$uri) {
        throw new \InvalidArgumentException("invalid uri: $uri");
    }

    if (!isset($uri['port'])) {
        throw new \UnexpectedValueException("port is required");
    }

    $port = $uri['port'];

    if ($uri['scheme'] !== 'tcp') {
        throw new \UnexpectedValueException("unsupported schema: ".$uri['scheme']);
    }

    $ip = $uri['host'];
    if (!@\inet_pton($ip)) {
        throw new \InvalidArgumentException("invalid ip: $ip");
    }

    $server = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
    socket_set_option($server, SOL_SOCKET, SO_REUSEADDR, 1);
    socket_set_nonblock($server);
    socket_bind($server, $ip, $port);
    socket_listen($server, $backlog);

    if ($func) {
        Loop::onReadable($server, function ($id, $server, $func) {
            $client = socket_accept($server);
            socket_set_nonblock($client);

            run(new \Fiber($func), $client);
        }, $func);
    }

    return $server;
}

function read0($fd, int $len, int $timeout_ms = 0)
{
    return \Fiber::yield([AWAIT_READ_AT_MOST, $fd, $len, $timeout_ms]);
}

function read($fd, int $len, int $timeout_ms = 0)
{
    if ($len === 0) {
        return '';
    }

    return \Fiber::yield([AWAIT_READ_BY_LENGTH, $fd, $len, $timeout_ms]);
}

function find($fd, string $stops, int $timeout_ms = 0)
{
    return \Fiber::yield([AWAIT_READ_BY_STOP, $fd, $stops, $timeout_ms]);
}

function write($fd, string $buf, int $timeout_ms = 0)
{
    return \Fiber::yield([AWAIT_WRITE_ALL, $fd, $buf, $timeout_ms]);
}

function sleep(int $delay_ms)
{
     \Fiber::yield([AWAIT_SLEEP, null, null, $delay_ms]);
}

function await_timeout(\Fiber $fiber, $id, $timeout_ms)
{
    if ($timeout_ms <= 0) {
        return;
    }

    $fiber->$id = Loop::delay($timeout_ms, function ($_id, $fiber) use($id) {
        if (isset($fiber->$id)) {
            Loop::cancel($id);
            unset($fiber->$id);

            return run($fiber, false);
        }
    }, $fiber);
}

function await_read_at_most(\Fiber $fiber, $fd, $len, $timeout_ms)
{
    $fiber->to_read_len = $len;
    $id = Loop::onReadable($fd, function ($id, $fd, $fiber) {
        Loop::cancel($id);
        if (isset($fiber->$id)) {
            Loop::cancel($fiber->$id);
            unset($fiber->$id);
        }

        $buf = socket_read($fd, $fiber->to_read_len);
        unset($fiber->to_read_len);
        return run($fiber, $buf);
    }, $fiber);

    await_timeout($fiber, $id, $timeout_ms);
}

function await_read_by_length(\Fiber $fiber, $fd, $len, $timeout_ms)
{
    if (isset($fiber->to_read_buf) && strlen($fiber->to_read_buf) >= $len) {
        $buf = substr($fiber->to_read_buf, 0, $len);
        $fiber->to_read_buf = substr($fiber->to_read_buf, $len);

        return run($fiber, $buf);
    } elseif (isset($fiber->to_read_buf)) {
        $len -= strlen($fiber->to_read_buf);
    }

    $fiber->to_read_len = $len;
    $id = Loop::onReadable($fd, function ($id, $fd, $fiber) {
        $buf = socket_read($fd, $fiber->to_read_len);

        if ($buf) {
            $fiber->to_read_buf .= $buf;
        }

        $buf_len = strlen($fiber->to_read_buf);
        if ($buf_len !== false && $buf_len < $fiber->to_read_len) {
            return;
        }

        if ($buf_len >= $fiber->to_read_len) {
            Loop::cancel($id);
            if (isset($fiber->$id)) {
                Loop::cancel($fiber->$id);
                unset($fiber->$id);
            }

            $buf = substr($fiber->to_read_buf, 0, $fiber->to_read_len);
            $fiber->to_read_buf = substr($fiber->to_read_buf, $fiber->to_read_len);
            $fiber->to_read_len = 0;

            return run($fiber, $buf);
        }
    }, $fiber);

    await_timeout($fiber, $id, $timeout_ms);
}

function await_read_by_stop(\Fiber $fiber, $fd, $stops, $timeout_ms)
{
    if (isset($fiber->to_read_buf)) {
        $pos = strpos($fiber->to_read_buf, $stops);
        if ($pos !== false) {
            $buf = substr($fiber->to_read_buf, 0, $pos);
            $fiber->to_read_buf = substr($fiber->to_read_buf, strlen($buf) + strlen($stops));

            return run($fiber, $buf);
        }
    }

    $fiber->to_read_stops = $stops;
    $id = Loop::onReadable($fd, function ($id, $fd, $fiber) {
        $buf = socket_read($fd, 1024);

        if ($buf === false && socket_last_error($fd) !== 11 /* EAGAIN */) {
            Loop::cancel($id);
            if (isset($fiber->$id)) {
                Loop::cancel($fiber->$id);
                unset($fiber->$id);
            }

            unset($fiber->to_read_stops);
            unset($fiber->to_read_buf);
            return run($fiber, false);
        }

        if ($buf) {
            $fiber->to_read_buf .= $buf;
        }

        $pos = strpos($fiber->to_read_buf, $fiber->to_read_stops);
        if ($pos === false) {
            return;
        }

        Loop::cancel($id);
        if (isset($fiber->$id)) {
            Loop::cancel($fiber->$id);
            unset($fiber->$id);
        }

        $buf = substr($fiber->to_read_buf, 0, $pos);
        $fiber->to_read_buf = substr($fiber->to_read_buf, strlen($buf) + strlen($fiber->to_read_stops));
        unset($fiber->to_read_stops);

        return run($fiber, $buf);
    }, $fiber);

    await_timeout($fiber, $id, $timeout_ms);
}

function await_write_all(\Fiber $fiber, $fd, $data, $timeout_ms)
{
    $len = socket_write($fd, $data);

    if (($len === false && socket_last_error($fd) !== 11 /* EAGAIN */) || $len === strlen($data)) {
        return run($fiber, $len);
    }

    $fiber->to_write_buf = substr($data, $len);
    $id = Loop::onWritable($fd, function ($id, $fd, $fiber) {
        $buf = $fiber->to_write_buf;

        $len = socket_write($fd, $buf);

        if ($len === strlen($buf) || $len === false) {
            Loop::cancel($id);
            if (isset($fiber->$id)) {
                Loop::cancel($fiber->$id);
                unset($fiber->$id);
            }

            unset($fiber->to_write_buf);
            return run($fiber, $len);
        }

        $fiber->to_write_buf = substr($buf, $len);
    }, $fiber);

    await_timeout($fiber, $id, $timeout_ms);
}

function await_connect(\Fiber $fiber, $fd, $timeout_ms)
{
    $id = Loop::onWritable($fd, function ($id, $fd, $fiber) {
        Loop::cancel($id);
        $status = @socket_getpeername($fd, $addr, $port);
        return run($fiber, $status);
    }, $fiber);

    await_timeout($fiber, $id, $timeout_ms);
}

function await_sleep(\Fiber $fiber, $delay_ms)
{
    Loop::delay($delay_ms, function ($id, $fiber) {
        run($fiber, null);
    }, $fiber);
}

function run(\Fiber $fiber, $arg = null)
{
    try {
        list($type, $fd, $data, $timeout_ms) = $fiber->resume($arg);

        if ($fiber->status() !== \Fiber::STATUS_SUSPENDED) {
            return;
        }

        switch ($type) {
        case AWAIT_READ_AT_MOST:
            return await_read_at_most($fiber, $fd, $data, $timeout_ms);
        case AWAIT_READ_BY_LENGTH:
            return await_read_by_length($fiber, $fd, $data, $timeout_ms);
        case AWAIT_READ_BY_STOP:
            return await_read_by_stop($fiber, $fd, $data, $timeout_ms);
        case AWAIT_WRITE_ALL:
            return await_write_all($fiber, $fd, $data, $timeout_ms);
        case AWAIT_CONNECT:
            return await_connect($fiber, $fd, $timeout_ms);
        case AWAIT_SLEEP:
            return await_sleep($fiber, $timeout_ms);
        }
    } catch (\Throwable $e) {
        echo 'Uncaught Exception: ', $e->getMessage(),
            ' in ', $e->getFile(), ' +', $e->getLine(),
            "\nStack trace\n", $e->getTraceAsString(), "\n";
    }
}

function go($func, $arg = null)
{
    Loop::defer(function () use($func, $arg) {
        run(new \Fiber($func), $arg);
    });
}

function once($func, $arg = null)
{
    run(new \Fiber($func), $arg);
    Loop::run();
}

function dig($name, $type = ResourceQTypes::A)
{
    return BasicResolver::init()->dig($name, $type);
}

function loop()
{
    Loop::run();
}
