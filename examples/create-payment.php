<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use AthMovil\{Amount, Client, Payment};

// This example contacts production and creates a payment request.
// Requires explicit opt-in plus a real business token and customer number.
if (getenv('ATHMOVIL_RUN_LIVE') !== 'yes') {
    fwrite(STDERR, "Set ATHMOVIL_RUN_LIVE=yes only when ready to create a real payment request.\n");
    exit(1);
}
$token = getenv('ATHMOVIL_PUBLIC_TOKEN');
$phone = getenv('ATHMOVIL_PHONE_NUMBER');
if ($token === false || $phone === false) {
    throw new RuntimeException('Set ATHMOVIL_PUBLIC_TOKEN and ATHMOVIL_PHONE_NUMBER.');
}
$client = new Client($token);
$created = $client->createPayment(new Payment(
    total: Amount::fromDecimal('1.00'),
    phoneNumber: $phone,
    metadata1: 'manual-integration-check',
));
// Store ecommerceId and auth_token privately, tied to your local order.
// A created ticket is not evidence of a completed payment.
echo 'Created ticket: ' . $created->requireString('ecommerceId') . PHP_EOL;
