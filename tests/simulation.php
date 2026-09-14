<?php
declare(strict_types=1);

use AthMovil\{Amount, Client, Payment, PaymentPoller};
use AthMovil\Exception\{ApiException, TransportException};
use AthMovil\Simulation\{Simulator, InMemoryStore, FileStore, FrozenClock, Fault, Operation};

function examplePayment(string $total = '25.00'): Payment
{
    return new Payment(Amount::fromDecimal($total), '9395550199', 'order-123', 'store-Manati', [
        ['name' => 'Coffee', 'description' => 'Puerto Rican coffee', 'quantity' => 2, 'price' => '8.00', 'tax' => '0.00', 'metadata' => 'SKU-COFFEE'],
        ['name' => 'Mug', 'description' => 'Ceramic mug', 'quantity' => 1, 'price' => '9.00', 'tax' => null, 'metadata' => 'SKU-MUG'],
    ], Amount::fromDecimal('25.00'), Amount::fromDecimal('0.00'));
}

function completed(Simulator $sim): array
{
    $client = $sim->client();
    $created = $client->createPayment(examplePayment());
    $sim->confirmPayment($created->ecommerceId());
    $paid = $client->authorizePayment($created->authToken());
    return [$client, $created, $paid];
}

function tempStoreDirectory(): string
{
    $directory = sys_get_temp_dir() . '/athmovil-test-' . bin2hex(random_bytes(8));
    if (!mkdir($directory, 0700)) { throw new RuntimeException('Cannot create test directory.'); }
    return $directory;
}

function removeStoreDirectory(string $directory): void
{
    foreach (glob($directory . '/*') ?: [] as $file) { unlink($file); }
    rmdir($directory);
}

$tests['simulation preserves submitted items and all metadata through payment and refund'] = static function (): void {
    $sim = new Simulator(clock: new FrozenClock(), seed: 'test-seed');
    [$client, $created, $paid] = completed($sim);
    same(true, $paid->isCompleted());
    same('25.00', (string) $paid->total());
    same('order-123', $paid->metadata1());
    same('store-Manati', $paid->metadata2());
    same('Coffee', $paid->items()[0]['name']);
    same(2, $paid->items()[0]['quantity']);
    same(8.0, $paid->items()[0]['price']);
    same('SKU-COFFEE', $paid->items()[0]['metadata']);
    same('SKU-MUG', $paid->items()[1]['metadata']);
    $refund = $client->refundPayment($paid->referenceNumber(), Amount::fromDecimal('5.00'));
    same(true, $refund->isRefundCompleted());
    same('Test Payer', $refund->refund()['name']);
    same('test.payer@example.com', $refund->originalTransaction()['email']);
    same('(787) 555-0100', $refund->refund()['phoneNumber']);
    same('order-123', $refund->originalTransaction()['metadata1']);
    same('store-Manati', $refund->originalTransaction()['metadata2']);
    same($paid->items(), $refund->originalTransaction()['items']);
    same(false, array_key_exists('simulation', $paid->data()));
    // The payment-status example has business fields but no payer fields.
    same(false, array_key_exists('name', $paid->data()));
    same(5.0, $client->findPayment($created->ecommerceId())->data()['totalRefundedAmount']);
};
$tests['simulation never silently repairs invoice arithmetic'] = static function (): void {
    $sim = new Simulator();
    $client = $sim->client();
    $created = $client->createPayment(examplePayment('20.00'));
    $found = $client->findPayment($created->ecommerceId());
    same('20.00', (string) $found->total());
    same(25.0, $found->data()['subTotal']);
    same(2, count($found->items()));
};
$tests['authorization requires confirmation and rejects duplicate completion'] = static function (): void {
    $sim = new Simulator(); $client = $sim->client();
    $created = $client->createPayment(examplePayment());
    raises(ApiException::class, static fn () => $client->authorizePayment($created->authToken()));
    $sim->confirmPayment($created->ecommerceId());
    same(true, $client->findPayment($created->ecommerceId())->isConfirmed());
    $paid = $client->authorizePayment($created->authToken());
    raises(ApiException::class, static fn () => $client->authorizePayment($created->authToken()));
    raises(LogicException::class, static fn () => $sim->cancelPayment($created->ecommerceId()));
    same($paid->referenceNumber(), $client->findPayment($created->ecommerceId())->referenceNumber());
};
$tests['partial refunds accumulate exactly and cannot exceed the balance'] = static function (): void {
    $sim = new Simulator(); [$client, $created, $paid] = completed($sim);
    $first = $client->refundPayment($paid->referenceNumber(), Amount::fromDecimal('0.29'));
    $second = $client->refundPayment($paid->referenceNumber(), Amount::fromDecimal('24.71'));
    same(false, $first->refund()['referenceNumber'] === $second->refund()['referenceNumber']);
    same(25.0, $second->originalTransaction()['totalRefundedAmount']);
    raises(ApiException::class, static fn () => $client->refundPayment($paid->referenceNumber(), Amount::fromDecimal('0.01')));
    same(25.0, $client->findPayment($created->ecommerceId())->data()['totalRefundedAmount']);
};
$tests['timeout after authorization persists effect and captured lost response'] = static function (): void {
    $sim = new Simulator(); $client = $sim->client(); $created = $client->createPayment(examplePayment());
    $sim->confirmPayment($created->ecommerceId());
    $sim->failNext(Operation::Authorize, Fault::TimeoutAfter);
    raises(TransportException::class, static fn () => $client->authorizePayment($created->authToken()));
    $history = $sim->history(); $capture = $history[count($history) - 1];
    same(null, $capture['response']);
    same(TransportException::class, $capture['exception']);
    same('COMPLETED', $capture['simulatedServerResponse']['data']['ecommerceStatus']);
    same(true, $client->findPayment($created->ecommerceId())->isCompleted());
};
$tests['timeout after refund persists refund and prevents excess subsequent refund'] = static function (): void {
    $sim = new Simulator(); [$client, $created, $paid] = completed($sim);
    $sim->failNext(Operation::Refund, Fault::TimeoutAfter);
    raises(TransportException::class, static fn () => $client->refundPayment($paid->referenceNumber(), Amount::fromDecimal('25')));
    same(25.0, $client->findPayment($created->ecommerceId())->data()['totalRefundedAmount']);
    raises(ApiException::class, static fn () => $client->refundPayment($paid->referenceNumber(), Amount::fromDecimal('25')));
};
$tests['timeout before authorization and rejection leave payment uncompleted'] = static function (): void {
    $sim = new Simulator(); $client = $sim->client(); $created = $client->createPayment(examplePayment());
    $sim->confirmPayment($created->ecommerceId());
    $sim->failNext(Operation::Authorize, Fault::TimeoutBefore);
    raises(TransportException::class, static fn () => $client->authorizePayment($created->authToken()));
    same('CONFIRM', $client->findPayment($created->ecommerceId())->paymentStatus());
    $sim->failNext(Operation::Authorize, Fault::Reject, 'BTRA_0005');
    same('BTRA_0005', raises(ApiException::class, static fn () => $client->authorizePayment($created->authToken()))->providerCode);
    same(true, $client->authorizePayment($created->authToken())->isCompleted());
};
$tests['customer cancellation and explicit expiration prevent authorization'] = static function (): void {
    foreach (['cancelPayment', 'expirePayment'] as $action) {
        $sim = new Simulator(); $client = $sim->client(); $created = $client->createPayment(examplePayment());
        $sim->{$action}($created->ecommerceId());
        same('CANCEL', $client->findPayment($created->ecommerceId())->paymentStatus());
        raises(ApiException::class, static fn () => $client->authorizePayment($created->authToken()));
    }
};
$tests['clock expiration occurs exactly at the deadline'] = static function (): void {
    $clock = new FrozenClock(); $sim = new Simulator(clock: $clock); $client = $sim->client();
    $created = $client->createPayment(examplePayment());
    $clock->advance(599);
    same('OPEN', $client->findPayment($created->ecommerceId())->paymentStatus());
    $sim->confirmPayment($created->ecommerceId());
    $clock->advance(1);
    same('CANCEL', $client->findPayment($created->ecommerceId())->paymentStatus());
    raises(ApiException::class, static fn () => $client->authorizePayment($created->authToken()));
};
$tests['phone update is captured while payer remains explicitly fictional'] = static function (): void {
    $sim = new Simulator(); $client = $sim->client(); $created = $client->createPayment(examplePayment());
    same('Update Phone Number', $client->updatePhoneNumber($created->ecommerceId(), '9395550102', $created->authToken())->message());
    $history = $sim->history();
    same('9395550102', $history[1]['request']['body']['phoneNumber']);
    $sim->confirmPayment($created->ecommerceId());
    raises(ApiException::class, static fn () => $client->updatePhoneNumber($created->ecommerceId(), '9395550103', $created->authToken()));
    $paid = $client->authorizePayment($created->authToken());
    $refund = $client->refundPayment($paid->referenceNumber(), Amount::fromDecimal('1'));
    same('(787) 555-0100', $refund->refund()['phoneNumber']);
};
$tests['merchant cancellation follows the normal client path'] = static function (): void {
    $sim = new Simulator(); $client = $sim->client(); $created = $client->createPayment(examplePayment());
    same('Payment Cancelled.', $client->cancelPayment($created->ecommerceId())->message());
    same('CANCEL', $client->findPayment($created->ecommerceId())->paymentStatus());
    raises(ApiException::class, static fn () => $client->cancelPayment($created->ecommerceId()));
};
$tests['business isolation covers IDs tokens refunds controls history and reset'] = static function (): void {
    $store = new InMemoryStore(); $a = new Simulator('A', $store); $b = new Simulator('B', $store);
    [$ac, $created, $paid] = completed($a); $bc = $b->client();
    raises(ApiException::class, static fn () => $bc->findPayment($created->ecommerceId()));
    raises(ApiException::class, static fn () => $bc->authorizePayment($created->authToken()));
    raises(ApiException::class, static fn () => $bc->updatePhoneNumber($created->ecommerceId(), '7875550100', $created->authToken()));
    raises(ApiException::class, static fn () => $bc->cancelPayment($created->ecommerceId()));
    raises(ApiException::class, static fn () => $bc->refundPayment($paid->referenceNumber(), Amount::fromDecimal('1')));
    raises(OutOfBoundsException::class, static fn () => $b->confirmPayment($created->ecommerceId()));
    foreach ($b->history() as $entry) { same([], $entry['after']); }
    $b->reset(); same([], $b->history());
    same(true, $ac->findPayment($created->ecommerceId())->isCompleted());
};
$tests['history redacts credential fields but preserves submitted business data'] = static function (): void {
    $sim = new Simulator(); [$client, $created, $paid] = completed($sim);
    $client->refundPayment($paid->referenceNumber(), Amount::fromDecimal('1'), 'test message');
    $export = $sim->exportHistory();
    same(false, str_contains($export, $created->authToken()));
    same(false, str_contains($export, 'sim-private-'));
    same(false, str_contains($export, 'sim-public-'));
    same(true, str_contains($export, 'SKU-COFFEE'));
    same(true, str_contains($export, '9395550199'));
    same(true, json_decode($export, true)['simulation']);
};
$tests['simulation rejects real credentials without network fallback'] = static function (): void {
    $sim = new Simulator();
    $client = new Client('real-looking-public', 'real-looking-private', $sim);
    raises(ApiException::class, static fn () => $client->createPayment(examplePayment()));
    same([], $sim->history()[0]['after']);
    same(false, str_contains($sim->exportHistory(), 'real-looking-public'));
    raises(LogicException::class, static fn () => $sim->send('GET', 'https://example.com', [], ''));
};
$tests['live transport rejects simulated credentials before networking'] = static function (): void {
    $sim = new Simulator(); $created = $sim->client()->createPayment(examplePayment());
    $live = new Client('business-public');
    raises(LogicException::class, static fn () => $live->authorizePayment($created->authToken()));
    raises(LogicException::class, static fn () => (new Client('sim-public-fixture'))->createPayment(examplePayment()));
};
$tests['seed and clock yield reproducible independent test fixtures'] = static function (): void {
    $a = new Simulator(clock: new FrozenClock(), seed: 'repeatable');
    $b = new Simulator(clock: new FrozenClock(), seed: 'repeatable');
    completed($a); completed($b);
    same($a->exportHistory(), $b->exportHistory());
};
$tests['reset invalidates old tokens even with a deterministic initial seed'] = static function (): void {
    $sim = new Simulator(seed: 'repeatable'); [$client, $created] = completed($sim);
    $sim->reset(); same([], $sim->history());
    $new = $client->createPayment(examplePayment());
    same(false, $new->ecommerceId() === $created->ecommerceId());
    raises(ApiException::class, static fn () => $client->authorizePayment($created->authToken()));
};
$tests['file storage resumes transactions and captures across instances'] = static function (): void {
    $directory = tempStoreDirectory();
    try {
        $a = new Simulator('persistent', new FileStore($directory));
        $created = $a->client()->createPayment(examplePayment());
        $b = new Simulator('persistent', new FileStore($directory));
        $b->confirmPayment($created->ecommerceId());
        same(true, $b->client()->authorizePayment($created->authToken())->isCompleted());
        same(true, $a->client()->findPayment($created->ecommerceId())->isCompleted());
        same(count($a->history()), count($b->history()));
    } finally { removeStoreDirectory($directory); }
};
$tests['store callbacks roll back on failure and corrupt files fail closed'] = static function (): void {
    $directory = tempStoreDirectory();
    try {
        foreach ([new InMemoryStore(), new FileStore($directory)] as $store) {
            $store->transaction('scope', static function (array &$s): void { $s['value'] = 1; });
            raises(RuntimeException::class, static fn () => $store->transaction('scope', static function (array &$s): void { $s['value'] = 2; throw new RuntimeException('abort'); }));
            same(1, $store->transaction('scope', static fn (array &$s) => $s['value']));
        }
        file_put_contents($directory . '/' . hash('sha256', 'scope') . '.json', '{broken');
        raises(RuntimeException::class, static fn () => (new FileStore($directory))->transaction('scope', static fn (array &$s) => $s));
    } finally { removeStoreDirectory($directory); }
};
$tests['competing PHP processes cannot over-refund shared file state'] = static function (): void {
    $directory = tempStoreDirectory();
    try {
        $sim = new Simulator('parallel', new FileStore($directory));
        [$client, $created, $paid] = completed($sim);
        $processes = [];
        for ($i = 0; $i < 2; ++$i) {
            $process = proc_open([PHP_BINARY, __DIR__ . '/refund-worker.php', $directory, $paid->referenceNumber()],
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            if (!is_resource($process)) { throw new RuntimeException('Unable to start refund worker.'); }
            fclose($pipes[0]); $processes[] = [$process, $pipes];
        }
        $results = [];
        foreach ($processes as [$process, $pipes]) {
            $results[] = trim(stream_get_contents($pipes[1]));
            $errors = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
            same('', $errors); same(0, proc_close($process));
        }
        sort($results); same(['completed', 'rejected'], $results);
        same(20.0, $client->findPayment($created->ecommerceId())->data()['totalRefundedAmount']);
    } finally { removeStoreDirectory($directory); }
};
$tests['bounded polling can advance simulation and never authorizes'] = static function (): void {
    $sim = new Simulator(); $client = $sim->client(); $created = $client->createPayment(examplePayment());
    $pauses = 0;
    $poller = new PaymentPoller($client, static function (int $ms) use ($sim, $created, &$pauses): void { ++$pauses; $sim->confirmPayment($created->ecommerceId()); });
    same(true, $poller->waitForConfirmation($created->ecommerceId(), maxAttempts: 3, intervalMilliseconds: 0)->isConfirmed());
    same(1, $pauses);
    same(false, $client->findPayment($created->ecommerceId())->isCompleted());
    $another = $client->createPayment(examplePayment());
    $attempts = 0;
    $poller = new PaymentPoller($client, static function (int $ms) use (&$attempts): void { ++$attempts; });
    same('OPEN', $poller->waitForConfirmation($another->ecommerceId(), maxAttempts: 3, intervalMilliseconds: 0)->paymentStatus());
    same(2, $attempts);
    raises(InvalidArgumentException::class, static fn () => $poller->waitForConfirmation($another->ecommerceId(), maxAttempts: 0));
};
