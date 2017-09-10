<?php
namespace f;

const AWAIT_READ_AT_MOST = 4;
const AWAIT_READ_BY_LENGTH = 0;
const AWAIT_READ_BY_STOP = 3;
const AWAIT_WRITE_ALL = 1;
const AWAIT_SLEEP = 2;

use Amp\Loop;
use LibDNS\Records\ResourceQTypes;
use Fiber\Dns\BasicResolver;

function read0($fd, int $len, int $timeout_ms = 0)
{
    return \Fiber::yield([AWAIT_READ_AT_MOST, $fd, $len, $timeout_ms]);
}

function read($fd, int $len, int $timeout_ms = 0)
{
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

            $ret = $fiber->resume(false);
            return run($fiber, $ret);
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
        $ret = $fiber->resume($buf);
        return run($fiber, $ret);
    }, $fiber);

    await_timeout($fiber, $id, $timeout_ms);
}

function await_read_by_length(\Fiber $fiber, $fd, $len, $timeout_ms)
{
    if (isset($fiber->to_read_buf) && strlen($fiber->to_read_buf) >= $len) {
        $buf = substr($fiber->to_read_buf, 0, $len);
        $fiber->to_read_buf = substr($fiber->to_read_buf, $len);

        $ret = $fiber->resume($buf);
        return run($fiber, $ret);
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

            $ret = $fiber->resume($buf);
            return run($fiber, $ret);
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

            $ret = $fiber->resume($buf);
            return run($fiber, $ret);
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
            $ret = $fiber->resume(false);
            return run($fiber, $ret);
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

        $ret = $fiber->resume($buf);
        return run($fiber, $ret);
    }, $fiber);

    await_timeout($fiber, $id, $timeout_ms);
}

function await_write_all(\Fiber $fiber, $fd, $data, $timeout_ms)
{
    $len = socket_write($fd, $data);

    if (($len === false && socket_last_error($fd) !== 11 /* EAGAIN */) || $len === strlen($data)) {
        $ret = $fiber->resume($len);
        return run($fiber, $ret);
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
            $ret = $fiber->resume($len);
            return run($fiber, $ret);
        }

        $fiber->to_write_buf = substr($buf, $len);
    }, $fiber);

    await_timeout($fiber, $id, $timeout_ms);
}

function await_sleep(\Fiber $fiber, $delay_ms)
{
    Loop::delay($delay_ms, function ($id, $fiber) {
        $ret = $fiber->resume();
        run($fiber, $ret);
    }, $fiber);
}

function run(\Fiber $fiber, $ret)
{
    if ($fiber->status() !== \Fiber::STATUS_SUSPENDED) {
        return;
    }

    list($type, $fd, $data, $timeout_ms) = $ret;

    switch ($type) {
    case AWAIT_READ_AT_MOST:
        return await_read_at_most($fiber, $fd, $data, $timeout_ms);
    case AWAIT_READ_BY_LENGTH:
        return await_read_by_length($fiber, $fd, $data, $timeout_ms);
    case AWAIT_READ_BY_STOP:
        return await_read_by_stop($fiber, $fd, $data, $timeout_ms);
    case AWAIT_WRITE_ALL:
        return await_write_all($fiber, $fd, $data, $timeout_ms);
    case AWAIT_SLEEP:
        return await_sleep($fiber, $timeout_ms);
    }
}

function dig($name, $type = ResourceQTypes::A)
{
    return BasicResolver::init()->dig($name, $type);
}
