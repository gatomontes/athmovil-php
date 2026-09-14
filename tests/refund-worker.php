<?php
declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'AthMovil\\')) {
        require dirname(__DIR__) . '/src/' . str_replace('\\', '/', substr($class, 9)) . '.php';
    }
});

use AthMovil\Amount;
use AthMovil\Exception\ApiException;
use AthMovil\Simulation\{Simulator, FileStore};

$client = (new Simulator('parallel', new FileStore($argv[1])))->client();
try {
    $client->refundPayment($argv[2], Amount::fromDecimal('20.00'));
    echo 'completed';
} catch (ApiException) { echo 'rejected'; }
