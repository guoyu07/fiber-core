<?php
namespace lv;

const AWAIT_READ_AT_MOST = 4;
const AWAIT_READ_BY_LENGTH = 0;
const AWAIT_READ_BY_STOP = 3;
const AWAIT_WRITE_ALL = 1;
const AWAIT_SLEEP = 2;

use Amp\Loop;
use \LibDNS\Messages\MessageFactory;
use \LibDNS\Messages\MessageTypes;
use \LibDNS\Records\QuestionFactory;
use \LibDNS\Records\ResourceTypes;
use \LibDNS\Records\ResourceQTypes;
use \LibDNS\Encoder\EncoderFactory;
use \LibDNS\Decoder\DecoderFactory;
use \LibDNS\Records\TypeDefinitions\TypeDefinitionManagerFactory;

function read0($fd, int $len)
{
    return await [AWAIT_READ_AT_MOST, $fd, $len];
}

function read($fd, int $len)
{
    return await [AWAIT_READ_BY_LENGTH, $fd, $len];
}

function find($fd, string $stops)
{
    return await [AWAIT_READ_BY_STOP, $fd, $stops];
}

function write($fd, string $buf)
{
    return await [AWAIT_WRITE_ALL, $fd, $buf];
}

function sleep(int $delay_ms)
{
    await [AWAIT_SLEEP, null, $delay_ms];
}

function run(\Fiber $fiber, $ret)
{
    if ($fiber->status() !== \Fiber::STATUS_SUSPENDED) {
        return;
    }

    list($type, $fd, $data) = $ret;

    switch ($type) {
    case AWAIT_READ_AT_MOST:
        $fiber->to_read_len = $data;
        Loop::onReadable($fd, function ($id, $fd, $fiber) {
            Loop::cancel($id);
            $buf = socket_read($fd, $fiber->to_read_len);
            unset($fiber->to_read_len);
            $ret = $fiber->resume($buf);
            return run($fiber, $ret);
        }, $fiber);
        break;
    case AWAIT_READ_BY_LENGTH:
        if (isset($fiber->to_read_buf) && strlen($fiber->to_read_buf) >= $data) {
            $buf = substr($fiber->to_read_buf, 0, $data);
            $fiber->to_read_buf = substr($fiber->to_read_buf, $data);

            $ret = $fiber->resume($buf);
            return run($fiber, $ret);
        } elseif (isset($fiber->to_read_buf)) {
            $data -= strlen($fiber->to_read_buf);
        }

        $fiber->to_read_len = $data;
        Loop::onReadable($fd, function ($id, $fd, $fiber) {
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

                $buf = substr($fiber->to_read_buf, 0, $fiber->to_read_len);
                $fiber->to_read_buf = substr($fiber->to_read_buf, $fiber->to_read_len);
                $fiber->to_read_len = 0;

                $ret = $fiber->resume($buf);
                return run($fiber, $ret);
            }
        }, $fiber);
        break;
    case AWAIT_READ_BY_STOP:
        if (isset($fiber->to_read_buf)) {
            $pos = strpos($fiber->to_read_buf, $data);
            if ($pos !== false) {
                $buf = substr($fiber->to_read_buf, 0, $pos);
                $fiber->to_read_buf = substr($fiber->to_read_buf, strlen($buf) + strlen($data));

                $ret = $fiber->resume($buf);
                return run($fiber, $ret);
            }
        }

        $fiber->to_read_stops = $data;
        Loop::onReadable($fd, function ($id, $fd, $fiber) {
            $buf = socket_read($fd, 1024);

            if ($buf === false && socket_last_error($fd) !== 11) {
                Loop::cancel($id);
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
            $buf = substr($fiber->to_read_buf, 0, $pos);
            $fiber->to_read_buf = substr($fiber->to_read_buf, strlen($buf) + strlen($fiber->to_read_stops));
            unset($fiber->to_read_stops);

            $ret = $fiber->resume($buf);
            return run($fiber, $ret);
        }, $fiber);
        break;
    case AWAIT_WRITE_ALL:
        $len = socket_write($fd, $data);

        if (($len == false && socket_last_error($fd) !== 11 /* EAGAIN */) || $len === strlen($data)) {
            $ret = $fiber->resume($len);
            return run($fiber, $ret);
        }

        $fiber->to_write_buf = substr($data, $len);
        Loop::onWritable($fd, function ($id, $fd, $fiber) {
            $buf = $fiber->to_write_buf;

            $len = socket_write($fd, $buf);

            if ($len === strlen($buf) || $len === false) {
                Loop::cancel($id);
                unset($fiber->to_write_buf);
                $ret = $fiber->resume($len);
                return run($fiber, $ret);
            }

            $fiber->to_write_buf = substr($buf, $len);
        }, $fiber);
        break;
    case AWAIT_SLEEP:
        Loop::delay($data, function ($id, $fiber) {
            Loop::cancel($id);
            $ret = $fiber->resume();
            run($fiber, $ret);
        }, $fiber);
        break;
    }
}

function dig($name, $type = ResourceQTypes::A, $server = '114.114.114.114')
{
    $question = (new QuestionFactory)->create(ResourceQTypes::A);
    $question->setName($name);

    $request = (new MessageFactory)->create(MessageTypes::QUERY);
    $request->getQuestionRecords()->add($question);
    $request->isRecursionDesired(true);

    $encoder = (new EncoderFactory)->create();

    $socket = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
    socket_set_nonblock($socket);
    socket_connect($socket, $server , 53);
    write($socket, $encoder->encode($request));

    $decoder = (new DecoderFactory)->create();
    $response = read0($socket, 512);
    $response = $decoder->decode($response);

    $ips = [];
    /** @var \LibDNS\Records\Resource $record */
    foreach ($response->getAnswerRecords() as $record) {
        $ips[] = [
            'name' => (string)$record->getName(),
            'type' => $record->getType(),
            'ttl' => $record->getTTL(),
            'data' => (string)$record->getData(),
        ];
    }

    return $ips;
}
