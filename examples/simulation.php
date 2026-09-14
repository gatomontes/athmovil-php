<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use AthMovil\{Amount, Payment};
use AthMovil\Simulation\{Simulator, FrozenClock};

$simulation = new Simulator(clock: new FrozenClock(), seed: 'checkout-demo');
$client = $simulation->client();
$created = $client->createPayment(new Payment(
    total: Amount::fromDecimal('25.00'),
    phoneNumber: '7875550100',
    metadata1: 'ORDER-1001',
    metadata2: 'STORE-MANATI',
    items: [
        ['name' => 'Coffee', 'description' => 'Ground coffee', 'quantity' => 2, 'price' => '8.00', 'tax' => null, 'metadata' => 'SKU-COFFEE'],
        ['name' => 'Mug', 'description' => 'Ceramic mug', 'quantity' => 1, 'price' => '9.00', 'tax' => null, 'metadata' => 'SKU-MUG'],
    ],
    subtotal: Amount::fromDecimal('25.00'),
    tax: Amount::fromDecimal('0.00'),
));
echo 'Pending: ' . $client->findPayment($created->ecommerceId())->paymentStatus() . PHP_EOL;
$simulation->confirmPayment($created->ecommerceId());
$paid = $client->authorizePayment($created->authToken());
echo 'Paid: ' . $paid->total() . ' | Order: ' . $paid->metadata1() . PHP_EOL;
$refund = $client->refundPayment($paid->referenceNumber(), Amount::fromDecimal('5.00'), 'Demo partial refund');
echo 'Refund: ' . $refund->refundStatus() . ' | Customer: ' . $refund->refund()['name'] . PHP_EOL;
echo $simulation->exportHistory() . PHP_EOL;
