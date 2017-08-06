<?php
namespace lv;

use Amp\Loop;
use \LibDNS\Messages\MessageFactory;
use \LibDNS\Messages\MessageTypes;
use \LibDNS\Records\QuestionFactory;
use \LibDNS\Records\ResourceTypes;
use \LibDNS\Records\ResourceQTypes;
use \LibDNS\Encoder\EncoderFactory;
use \LibDNS\Decoder\DecoderFactory;
use \LibDNS\Records\TypeDefinitions\TypeDefinitionManagerFactory;

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

function dig($queryName, $type = ResourceQTypes::A, $serverIP = '1.2.4.8')
{
    $question = (new QuestionFactory)->create(ResourceQTypes::A);
    $question->setName($queryName);

    // Create request message
    $request = (new MessageFactory)->create(MessageTypes::QUERY);
    $request->getQuestionRecords()->add($question);
    $request->isRecursionDesired(true);

    // Encode request message
    $encoder = (new EncoderFactory)->create();
    $requestPacket = $encoder->encode($request);

    // Send request
    $socket = stream_socket_client("udp://$serverIP:53");
    stream_socket_sendto($socket, $requestPacket);

    stream_set_blocking($socket, 0);
    await [0, $socket];

    // Decode response message
    $decoder = (new DecoderFactory)->create();
    $responsePacket = fread($socket, 512);
    $response = $decoder->decode($responsePacket);

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
