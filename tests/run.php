<?php
declare(strict_types=1);

// Dependency-free offline contract tests. No network transport is used here.
spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'AthMovil\\')) {
        require dirname(__DIR__) . '/src/' . str_replace('\\', '/', substr($class, 9)) . '.php';
    }
});

use AthMovil\{Amount, Client, Payment};
use AthMovil\Exception\{ApiException, ProtocolException, TransportException};
use AthMovil\Http\{Response, Transport};

final class FakeTransport implements Transport
{
    public array $calls = [];
    public function __construct(public Response|Throwable $next) {}
    public function send(string $method, string $url, array $headers, string $body): Response
    {
        $this->calls[] = compact('method', 'url', 'headers', 'body');
        if ($this->next instanceof Throwable) { throw $this->next; }
        return $this->next;
    }
}

function same(mixed $expected, mixed $actual): void
{
    if ($expected !== $actual) { throw new RuntimeException('Values differ: ' . var_export([$expected, $actual], true)); }
}

function raises(string $class, callable $operation): Throwable
{
    try { $operation(); } catch (Throwable $error) {
        if ($error instanceof $class) { return $error; }
        throw $error;
    }
    throw new RuntimeException('Expected ' . $class);
}

function fixture(mixed $data = []): array
{
    $transport = new FakeTransport(new Response(200, json_encode(['status' => 'success', 'data' => $data], JSON_THROW_ON_ERROR)));
    return [new Client('test-public', 'test-private', $transport), $transport];
}

$tests = [];
$tests['decimal arithmetic stays exact'] = static function (): void {
    same(29, Amount::fromDecimal('0.29')->cents);
    same('10.00', (string) Amount::fromDecimal('10'));
    same('10.50', (string) Amount::fromDecimal('10.5'));
    foreach (['-1', '1e2', '1.001', 'NaN', '', '01.00', '1,000.00', '10000000'] as $value) {
        raises(InvalidArgumentException::class, static fn () => Amount::fromDecimal($value));
    }
};
$tests['payment creation sends exact payload and protects credentials'] = static function (): void {
    [$client, $http] = fixture(['ecommerceId' => 'ticket-1', 'auth_token' => 'test-bearer']);
    $response = $client->createPayment(new Payment(Amount::fromDecimal('10.29'), '7875550100', 'order-1'));
    $call = $http->calls[0];
    same('POST', $call['method']);
    same('https://payments.athmovil.com/api/business-transaction/ecommerce/payment', $call['url']);
    same(['env' => 'production', 'total' => '10.29', 'phoneNumber' => '7875550100', 'metadata1' => 'order-1', 'metadata2' => '', 'items' => [], 'timeout' => 600, 'publicToken' => 'test-public'], json_decode($call['body'], true));
    same(['Accept: application/json', 'Content-Type: application/json'], $call['headers']);
    same('ticket-1', $response->ecommerceId());
    same('test-bearer', $response->authToken());
    same(false, $response->isCompleted());
    ob_start(); var_dump($client, $response); $debug = ob_get_clean();
    same(false, str_contains($debug, 'test-private'));
    same(false, str_contains($debug, 'test-bearer'));
};
$tests['payment limits and timeout bounds'] = static function (): void {
    foreach (['0.99', '1500.01'] as $value) {
        raises(InvalidArgumentException::class, static fn () => new Payment(Amount::fromDecimal($value), '7875550100'));
    }
    foreach ([119, 601] as $timeout) {
        raises(InvalidArgumentException::class, static fn () => new Payment(Amount::fromDecimal('1'), '7875550100', paymentTimeoutSeconds: $timeout));
    }
    foreach (['1.00', '1500.00'] as $value) { new Payment(Amount::fromDecimal($value), '7875550100'); }
};
$tests['metadata counts Unicode characters'] = static function (): void {
    new Payment(Amount::fromDecimal('1'), '7875550100', str_repeat('ó', 40));
    raises(InvalidArgumentException::class, static fn () => new Payment(Amount::fromDecimal('1'), '7875550100', str_repeat('ó', 41)));
    raises(InvalidArgumentException::class, static fn () => new Payment(Amount::fromDecimal('1'), '7875550100', "\xFF"));
};
$tests['items and optional amounts serialize correctly'] = static function (): void {
    $item = ['name' => 'Coffee', 'description' => 'Ground coffee', 'quantity' => 1, 'price' => '9.00', 'tax' => null, 'metadata' => null];
    $payment = new Payment(Amount::fromDecimal('10'), '7875550100', items: [$item], subtotal: Amount::fromDecimal('9'), tax: Amount::fromDecimal('1'));
    same('9.00', $payment->payload()['subtotal']);
    same('1.00', $payment->payload()['tax']);
    same([$item], $payment->payload()['items']);
    raises(InvalidArgumentException::class, static fn () => new Payment(Amount::fromDecimal('1'), '7875550100', items: [['name' => 'Incomplete']]));
    $item['price'] = 9.00;
    raises(InvalidArgumentException::class, static fn () => new Payment(Amount::fromDecimal('1'), '7875550100', items: [$item]));
};
$tests['status is preserved without treating confirmation as completion'] = static function (): void {
    foreach (['OPEN', 'CONFIRM', 'CONFIRMED', 'COMPLETED', 'CANCEL', 'FUTURE_STATUS'] as $status) {
        [$client, $http] = fixture(['ecommerceStatus' => $status]);
        $response = $client->findPayment('ticket-1');
        same($status, $response->paymentStatus());
        same($status === 'COMPLETED', $response->isCompleted());
        same('https://payments.athmovil.com/api/business-transaction/ecommerce/business/findPayment', $http->calls[0]['url']);
        same(['ecommerceId' => 'ticket-1', 'publicToken' => 'test-public'], json_decode($http->calls[0]['body'], true));
    }
};
$tests['find supports explicit bearer'] = static function (): void {
    [$client, $http] = fixture();
    $client->findPayment('ticket-1', 'test-bearer');
    same('Authorization: Bearer test-bearer', $http->calls[0]['headers'][2]);
};
$tests['authorization uses bearer and empty body'] = static function (): void {
    [$client, $http] = fixture(['ecommerceStatus' => 'COMPLETED', 'referenceNumber' => 'receipt-1']);
    $response = $client->authorizePayment('test-bearer');
    same('POST', $http->calls[0]['method']);
    same('https://payments.athmovil.com/api/business-transaction/ecommerce/authorization', $http->calls[0]['url']);
    same('', $http->calls[0]['body']);
    same('Authorization: Bearer test-bearer', $http->calls[0]['headers'][2]);
    same('receipt-1', $response->referenceNumber());
    same(true, $response->isCompleted());
};
$tests['phone update uses PUT and transaction bearer'] = static function (): void {
    [$client, $http] = fixture('Update Phone Number');
    same('Update Phone Number', $client->updatePhoneNumber('ticket-1', '9395550100', 'test-bearer')->data());
    same('PUT', $http->calls[0]['method']);
    same('https://payments.athmovil.com/api/business-transaction/ecommerce/business/updatePhoneNumber', $http->calls[0]['url']);
    same(['ecommerceId' => 'ticket-1', 'phoneNumber' => '9395550100'], json_decode($http->calls[0]['body'], true));
    same('Authorization: Bearer test-bearer', $http->calls[0]['headers'][2]);
};
$tests['cancel accepts string success data'] = static function (): void {
    [$client, $http] = fixture('Payment Cancelled.');
    same('Payment Cancelled.', $client->cancelPayment('ticket-1')->data());
    same('POST', $http->calls[0]['method']);
    same('https://payments.athmovil.com/api/business-transaction/ecommerce/business/cancel', $http->calls[0]['url']);
    same(['ecommerceId' => 'ticket-1', 'publicToken' => 'test-public'], json_decode($http->calls[0]['body'], true));
};
$tests['refund isolates private token to refund body'] = static function (): void {
    [$client, $http] = fixture(['refund' => ['status' => 'COMPLETED']]);
    $client->refundPayment('receipt-1', Amount::fromDecimal('0.29'), 'Requested');
    same('POST', $http->calls[0]['method']);
    same('https://payments.athmovil.com/api/business-transaction/ecommerce/refund', $http->calls[0]['url']);
    same(['referenceNumber' => 'receipt-1', 'amount' => '0.29', 'publicToken' => 'test-public', 'privateToken' => 'test-private', 'message' => 'Requested'], json_decode($http->calls[0]['body'], true));
    same(2, count($http->calls[0]['headers']));
};
$tests['invalid operations fail before transport'] = static function (): void {
    [$client, $http] = fixture();
    raises(InvalidArgumentException::class, static fn () => $client->authorizePayment("token\r\nX-Evil: yes"));
    raises(InvalidArgumentException::class, static fn () => $client->cancelPayment(' '));
    raises(InvalidArgumentException::class, static fn () => $client->updatePhoneNumber('ticket', '+17875550100', 'token'));
    raises(InvalidArgumentException::class, static fn () => $client->refundPayment('receipt', Amount::fromDecimal('0')));
    raises(InvalidArgumentException::class, static fn () => $client->refundPayment('receipt', Amount::fromDecimal('1'), str_repeat('x', 51)));
    $noPrivateToken = new Client('public', transport: $http);
    raises(LogicException::class, static fn () => $noPrivateToken->refundPayment('receipt', Amount::fromDecimal('1')));
    same([], $http->calls);
};
$tests['HTTP and provider errors carry codes without body or message'] = static function (): void {
    foreach ([200, 400, 401, 409, 429, 500] as $status) {
        $http = new FakeTransport(new Response($status, '{"status":"error","errorcode":"BTRA_0032","message":"secret-customer-data","data":null}'));
        $client = new Client('public', transport: $http);
        $error = raises(ApiException::class, static fn () => $client->authorizePayment('token'));
        same($status, $error->httpStatus);
        same('BTRA_0032', $error->providerCode);
        same(false, str_contains($error->getMessage(), 'secret-customer-data'));
        same(null, $error->getPrevious());
        same(1, count($http->calls));
    }
};
$tests['malformed and unexpected success envelopes are rejected'] = static function (): void {
    foreach (['<html>error</html>', 'null', '[]', '{"status":"unknown","data":{}}', '{"status":"success"}', '{"status":true,"data":{}}'] as $body) {
        $client = new Client('public', transport: new FakeTransport(new Response(200, $body)));
        raises(ProtocolException::class, static fn () => $client->findPayment('ticket'));
    }
};
$tests['redirect and non-JSON HTTP error responses are not successes'] = static function (): void {
    foreach ([302, 503] as $status) {
        $http = new FakeTransport(new Response($status, '<html>error</html>'));
        $client = new Client('public', transport: $http);
        raises(ApiException::class, static fn () => $client->findPayment('ticket'));
        same(1, count($http->calls));
    }
};
$tests['transport errors are never retried'] = static function (): void {
    $http = new FakeTransport(new TransportException('Timeout: remote outcome unknown.'));
    $client = new Client('public', 'private', $http);
    raises(TransportException::class, static fn () => $client->refundPayment('receipt', Amount::fromDecimal('1')));
    same(1, count($http->calls));
};
$tests['required response fields fail explicitly'] = static function (): void {
    [$client] = fixture(['ecommerceId' => 'ticket']);
    raises(ProtocolException::class, static fn () => $client->findPayment('ticket')->requireString('auth_token'));
};

require __DIR__ . '/simulation.php';

$failures = 0;
foreach ($tests as $name => $test) {
    try { $test(); echo "PASS: $name\n"; }
    catch (Throwable $error) { ++$failures; fwrite(STDERR, "FAIL: $name: " . $error->getMessage() . "\n"); }
}
echo count($tests) . ' tests, ' . $failures . " failures\n";
exit($failures === 0 ? 0 : 1);
