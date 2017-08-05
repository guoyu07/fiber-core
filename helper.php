<?php
namespace lv;

use Amp\Loop;

function read($fd, $len)
{
    await [0, $fd];
    return socket_read($fd, $len);
}

function write($fd, $buf)
{
    return socket_write($fd, $buf);
}

function sleep($delay_ms)
{
    await [2, $delay_ms];
}

function run($fiber, $ret = null)
{
    if ($fiber->status() !== 1) {
        return;
    }

    list($type, $data) = $ret;

    switch ($type) {
    case 0:
        Loop::onReadable($data, function ($id, $fd, $fiber) {
            Loop::cancel($id);
            $ret = $fiber->resume();
            run($fiber, $ret);
        }, $fiber);
        break;
    case 2:
        Loop::delay($data, function ($id, $fiber) {
            Loop::cancel($id);
            $ret = $fiber->resume();
            run($fiber, $ret);
        }, $fiber);
        break;
    }
}
