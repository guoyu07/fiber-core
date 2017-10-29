<?php
require __DIR__.'/../vendor/autoload.php';

use Amp\Loop;
use Fiber\Helper as f;

Loop::run(function () {
    $f = new Fiber(function () {redis(); });

    f\run($f);
});

function redis()
{
    $db = new \Fiber\Redis\Connection('127.0.0.1');

    // var_dump($db->set('lv', 123));
    var_dump($db->incr('lv'));
    // var_dump($db->expire('lv', 30));
    var_dump($db->ttl('lv'));
    var_dump($db->keys('*'));
    var_dump($db->del('b'));
}
