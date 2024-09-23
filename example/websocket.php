<?php declare(strict_types=1);

use function Co\wait;

include __DIR__ . '/../vendor/autoload.php';

$connection = Co\Net::WebSocket()->connect('wss://socket-r2d9g7x1y3m.wat0n.com/');
$connection->onOpen(function () use ($connection) {
    echo "WebSocket connected\n";
});

wait();
