<?php
declare(strict_types=1);

namespace AthMovil\Simulation;

use AthMovil\{Amount, Client, Validation};
use AthMovil\Exception\TransportException;
use AthMovil\Http\{Response, Transport};

/** Entirely local: no network implementation or fallback is held by this class. */
final class Simulator implements Transport
{
    private const BASE = 'https://payments.athmovil.com/api/business-transaction/ecommerce';
    private readonly Store $store;
    private readonly Clock $clock;
    private readonly string $scope;
    private readonly string $publicToken;
    private readonly string $privateToken;

    public function __construct(
        string $businessId = 'test-business',
        ?Store $store = null,
        ?Clock $clock = null,
        private readonly ?string $seed = null,
    ) {
        Validation::nonBlank($businessId);
        $this->scope = hash('sha256', $businessId);
        $this->publicToken = 'sim-public-' . $this->scope;
        $this->privateToken = 'sim-private-' . $this->scope;
        $this->store = $store ?? new InMemoryStore();
        $this->clock = $clock ?? new SystemClock();
    }

    public function client(): Client { return new Client($this->publicToken, $this->privateToken, $this); }

    public function confirmPayment(string $id): void { $this->control($id, 'confirm', 'CONFIRM'); }
    public function cancelPayment(string $id): void { $this->control($id, 'customer_cancel', 'CANCEL'); }
    public function expirePayment(string $id): void { $this->control($id, 'expire', 'CANCEL'); }

    /** One-shot fault, scoped to this simulated business and operation. */
    public function failNext(Operation $operation, Fault $fault, string $errorCode = 'BTRA_0005'): void
    {
        if (!preg_match('/^BTRA_[0-9]{4}$/D', $errorCode)) {
            throw new \InvalidArgumentException('Use a BTRA_0000 style error code.');
        }
        $this->store->transaction($this->scope, function (array &$state) use ($operation, $fault, $errorCode): void {
            $this->initialize($state);
            $state['faults'][$operation->value] = ['mode' => $fault->value, 'code' => $errorCode];
        });
    }

    public function history(): array
    {
        return $this->store->transaction($this->scope, function (array &$state): array {
            $this->initialize($state);
            return $state['history'];
        });
    }

    public function exportHistory(): string
    {
        return json_encode(['simulation' => true, 'history' => $this->history()], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /** Reset only this business. A new epoch invalidates old transaction tokens. */
    public function reset(): void
    {
        $this->store->transaction($this->scope, function (array &$state): void {
            $state = [];
            $this->initialize($state);
            $state['epoch'] = bin2hex(random_bytes(16));
        });
    }

    public function send(string $method, string $url, #[\SensitiveParameter] array $headers, #[\SensitiveParameter] string $body): Response
    {
        $routes = [
            'POST ' . self::BASE . '/payment' => Operation::Create,
            'POST ' . self::BASE . '/business/findPayment' => Operation::Find,
            'POST ' . self::BASE . '/authorization' => Operation::Authorize,
            'PUT ' . self::BASE . '/business/updatePhoneNumber' => Operation::UpdatePhone,
            'POST ' . self::BASE . '/business/cancel' => Operation::Cancel,
            'POST ' . self::BASE . '/refund' => Operation::Refund,
        ];
        $operation = $routes[$method . ' ' . $url] ?? null;
        if ($operation === null) { throw new \LogicException('Unsupported simulator route; no network request was made.'); }
        $payload = $body === '' ? [] : json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($payload)) { throw new \InvalidArgumentException('Simulator expects a JSON object.'); }
        $bearer = null;
        foreach ($headers as $header) {
            if (str_starts_with(strtolower($header), 'authorization: bearer ')) { $bearer = substr($header, 22); }
        }
        $result = $this->store->transaction($this->scope, function (array &$state) use ($operation, $method, $url, $payload, $bearer, $headers): array {
            $this->initialize($state);
            $this->expireDue($state);
            $before = $this->snapshot($state);
            $fault = $state['faults'][$operation->value] ?? null;
            unset($state['faults'][$operation->value]);
            $timeout = false;
            if ($fault !== null && $fault['mode'] === Fault::TimeoutBefore->value) {
                $response = null;
                $timeout = true;
            } elseif ($fault !== null && $fault['mode'] === Fault::Reject->value) {
                $response = $this->error($fault['code']);
            } else {
                $response = $this->dispatch($state, $operation, $payload, $bearer);
                $timeout = $fault !== null && $fault['mode'] === Fault::TimeoutAfter->value;
            }
            $capturedPayload = $payload;
            foreach (['publicToken', 'privateToken'] as $key) {
                if (array_key_exists($key, $capturedPayload)) { $capturedPayload[$key] = '[REDACTED]'; }
            }
            $responseEnvelope = $response === null ? null : json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
            if (isset($responseEnvelope['data']['auth_token'])) { $responseEnvelope['data']['auth_token'] = '[REDACTED]'; }
            $state['history'][] = [
                'simulation' => true, 'at' => $this->clock->now(), 'operation' => $operation->value,
                'request' => ['method' => $method, 'path' => substr($url, strlen(self::BASE)),
                    'headers' => array_map(static fn (string $h): string => str_starts_with(strtolower($h), 'authorization:') ? 'Authorization: [REDACTED]' : $h, $headers),
                    'body' => $capturedPayload],
                'response' => $timeout ? null : ['httpStatus' => $response->statusCode, 'body' => $responseEnvelope],
                'exception' => $timeout ? TransportException::class : null,
                'simulatedServerResponse' => $timeout && $response !== null ? $responseEnvelope : null,
                'before' => $before, 'after' => $this->snapshot($state),
            ];
            return ['response' => $response, 'timeout' => $timeout];
        });
        // Throw after storage commits, so a timeout can represent a completed effect.
        if ($result['timeout']) { throw new TransportException('Simulated timeout; remote outcome is unknown to the caller.'); }
        return $result['response'];
    }

    private function initialize(array &$state): void
    {
        if ($state === []) {
            $state = ['version' => 1, 'epoch' => $this->seed ?? bin2hex(random_bytes(16)), 'sequence' => 0,
                'payments' => [], 'faults' => [], 'history' => []];
        }
        if (($state['version'] ?? null) !== 1 || !is_string($state['epoch'] ?? null)
            || !is_int($state['sequence'] ?? null) || !is_array($state['payments'] ?? null)
            || !is_array($state['faults'] ?? null) || !is_array($state['history'] ?? null)) {
            throw new \RuntimeException('Unrecognized simulator state.');
        }
    }

    private function nextId(array &$state, string $kind): string
    {
        ++$state['sequence'];
        return 'sim-' . $kind . '-' . substr(hash('sha256', $this->scope . ':' . $state['epoch'] . ':' . $state['sequence']), 0, 24);
    }

    private function control(string $id, string $action, string $target): void
    {
        $this->store->transaction($this->scope, function (array &$state) use ($id, $action, $target): void {
            $this->initialize($state);
            $this->expireDue($state);
            if (!isset($state['payments'][$id])) { throw new \OutOfBoundsException('Simulated payment not found in this business.'); }
            $before = $this->snapshot($state);
            $payment = &$state['payments'][$id];
            $allowed = $action === 'confirm' ? ['OPEN'] : ['OPEN', 'CONFIRM'];
            if (!in_array($payment['status'], $allowed, true)) { throw new \LogicException('Simulated payment cannot make this transition.'); }
            $payment['status'] = $target;
            $state['history'][] = ['simulation' => true, 'at' => $this->clock->now(), 'control' => $action,
                'ecommerceId' => $id, 'before' => $before, 'after' => $this->snapshot($state)];
        });
    }

    private function expireDue(array &$state): void
    {
        foreach ($state['payments'] as $id => &$payment) {
            if (in_array($payment['status'], ['OPEN', 'CONFIRM'], true) && $payment['expiresAt'] <= $this->clock->now()) {
                $from = $payment['status'];
                $payment['status'] = 'CANCEL';
                $state['history'][] = ['simulation' => true, 'at' => $this->clock->now(), 'control' => 'clock_expiry',
                    'ecommerceId' => $id, 'from' => $from, 'to' => 'CANCEL'];
            }
        }
    }

    private function dispatch(array &$state, Operation $operation, array $payload, ?string $bearer): Response
    {
        if (in_array($operation, [Operation::Create, Operation::Find, Operation::Cancel, Operation::Refund], true)
            && ($payload['publicToken'] ?? null) !== $this->publicToken) { return $this->error('BTRA_0401', 401); }
        if ($operation === Operation::Refund && ($payload['privateToken'] ?? null) !== $this->privateToken) {
            return $this->error('BTRA_0401', 401);
        }
        if ($operation === Operation::Create) {
            $id = $this->nextId($state, 'payment');
            $token = $this->nextId($state, 'auth');
            unset($payload['publicToken'], $payload['privateToken']);
            $state['payments'][$id] = ['id' => $id, 'token' => $token, 'request' => $payload, 'status' => 'OPEN',
                'expiresAt' => $this->clock->now() + $payload['timeout'], 'reference' => '', 'completedAt' => null,
                'refundedCents' => 0, 'refunds' => []];
            return $this->success(['ecommerceId' => $id, 'auth_token' => $token]);
        }
        $id = null;
        if ($operation === Operation::Authorize) {
            foreach ($state['payments'] as $key => $candidate) {
                if ($bearer !== null && hash_equals($candidate['token'], $bearer)) { $id = $key; break; }
            }
            if ($id === null) { return $this->error('BTRA_0401', 401); }
        } elseif ($operation === Operation::Refund) {
            foreach ($state['payments'] as $key => $candidate) {
                if ($candidate['reference'] !== '' && $candidate['reference'] === ($payload['referenceNumber'] ?? null)) { $id = $key; break; }
            }
            if ($id === null) { return $this->error('BTRA_0053'); }
        } else {
            $id = $payload['ecommerceId'] ?? '';
            if (!is_string($id) || !isset($state['payments'][$id])) { return $this->error('BTRA_0031'); }
        }
        $payment = &$state['payments'][$id];
        if (($operation === Operation::UpdatePhone || ($operation === Operation::Find && $bearer !== null))
            && ($bearer === null || !hash_equals($payment['token'], $bearer))) { return $this->error('BTRA_0401', 401); }
        switch ($operation) {
            case Operation::Find:
                return $this->success($this->paymentData($payment));
            case Operation::Authorize:
                if ($payment['status'] !== 'CONFIRM') { return $this->error('BTRA_0032'); }
                $payment['status'] = 'COMPLETED';
                $payment['reference'] = $this->nextId($state, 'receipt');
                $payment['completedAt'] = $this->clock->now();
                return $this->success($this->paymentData($payment));
            case Operation::UpdatePhone:
                if ($payment['status'] !== 'OPEN') { return $this->error('BTRA_0035'); }
                $payment['request']['phoneNumber'] = $payload['phoneNumber'];
                return $this->success('Update Phone Number');
            case Operation::Cancel:
                if (!in_array($payment['status'], ['OPEN', 'CONFIRM'], true)) { return $this->error('BTRA_0035'); }
                $payment['status'] = 'CANCEL';
                return $this->success('Payment Cancelled.');
            case Operation::Refund:
                if ($payment['status'] !== 'COMPLETED') { return $this->error('BTRA_0035'); }
                $amount = Amount::fromDecimal($payload['amount']);
                $total = Amount::fromDecimal($payment['request']['total']);
                if ($amount->cents < 1 || $amount->cents > $total->cents - $payment['refundedCents']) { return $this->error('BTRA_0004'); }
                $payment['refundedCents'] += $amount->cents;
                $refund = ['transactionType' => 'REFUND', 'status' => 'COMPLETED', 'refundedAmount' => $this->number($payload['amount']),
                    'date' => (string) ($this->clock->now() * 1000), 'referenceNumber' => $this->nextId($state, 'refund'),
                    'dailyTransactionID' => str_pad((string) (count($payment['refunds']) + 1), 4, '0', STR_PAD_LEFT)] + $this->customer();
                $payment['refunds'][] = $refund;
                $data = $this->paymentData($payment);
                $original = ['transactionType' => 'PAYMENT', 'status' => 'COMPLETED',
                    'date' => (string) ($payment['completedAt'] * 1000), 'referenceNumber' => $payment['reference'],
                    'dailyTransactionID' => $data['dailyTransactionId'], 'message' => '',
                    'total' => $data['total'], 'tax' => $data['tax'], 'subtotal' => $data['subTotal'],
                    'fee' => $data['fee'], 'netAmount' => $data['netAmount'], 'totalRefundedAmount' => $data['totalRefundedAmount'],
                    'metadata1' => $data['metadata1'], 'metadata2' => $data['metadata2'], 'items' => $data['items']] + $this->customer();
                return $this->success(['refund' => $refund, 'originalTransaction' => $original]);
            default:
                throw new \LogicException('Unhandled simulated operation.');
        }
    }

    private function customer(): array
    {
        return ['name' => 'Test Payer', 'phoneNumber' => '(787) 555-0100', 'email' => 'test.payer@example.com'];
    }

    private function paymentData(array $payment): array
    {
        $request = $payment['request'];
        $items = array_map(function (array $item): array {
            $item['price'] = $this->number($item['price']);
            $item['tax'] = $item['tax'] === null ? null : $this->number($item['tax']);
            return $item;
        }, $request['items']);
        return ['ecommerceStatus' => $payment['status'], 'ecommerceId' => $payment['id'],
            'referenceNumber' => $payment['reference'], 'businessCustomerId' => 'sim-business-' . substr($this->scope, 0, 12),
            'transactionDate' => $payment['completedAt'] === null ? '' : gmdate('Y-m-d H:i:s', $payment['completedAt']),
            'dailyTransactionId' => $payment['completedAt'] === null ? '' : substr($payment['reference'], -4),
            'businessName' => 'Test Business', 'businessPath' => 'TestBusiness', 'industry' => 'COMPUTERS',
            'subTotal' => $this->number($request['subtotal'] ?? '0.00'), 'tax' => $this->number($request['tax'] ?? '0.00'),
            'total' => $this->number($request['total']), 'fee' => 0.0,
            'netAmount' => $payment['status'] === 'COMPLETED' ? $this->number($request['total']) : 0.0,
            'totalRefundedAmount' => $payment['refundedCents'] / 100,
            'metadata1' => $request['metadata1'], 'metadata2' => $request['metadata2'], 'items' => $items, 'isNonProfit' => false];
    }

    /** Convert only at the wire boundary to match upstream numeric JSON types. */
    private function number(string $decimal): float { return Amount::fromDecimal($decimal)->cents / 100; }

    private function snapshot(array $state): array
    {
        return array_map(static fn (array $payment): array => ['status' => $payment['status'],
            'referenceNumber' => $payment['reference'], 'refundedCents' => $payment['refundedCents']], $state['payments']);
    }

    private function success(mixed $data): Response
    {
        return new Response(200, json_encode(['status' => 'success', 'data' => $data], JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_UNICODE));
    }

    private function error(string $code, int $status = 409): Response
    {
        return new Response($status, json_encode(['status' => 'error', 'message' => 'Simulated request rejected.', 'errorcode' => $code, 'data' => null], JSON_THROW_ON_ERROR));
    }

    public function __debugInfo(): array { return ['simulation' => true]; }
}
