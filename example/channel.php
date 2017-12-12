<?php
require './vendor/autoload.php';

use Fiber\Helper as f;
use Fiber\Util\Channel;

f\once(function () {
    $c = new Channel;

    f\go(function() use($c) {
        for ($i = 0; $i < 4; $i++) {
            $j = $c->read();
            echo "\$c -> $j\n";
        }
    });

    f\go(function() use($c) {
        for ($i = 0; $i < 4; $i++) {
            echo "\$c <- $i\n";
            $c->write($i);
        }
    });
});
