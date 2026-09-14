<?php
declare(strict_types=1);

namespace AthMovil;

use AthMovil\Exception\ApiException;
use AthMovil\Exception\ProtocolException;
use AthMovil\Http\CurlTransport;
use AthMovil\Http\Transport;
use AthMovil\Result\{CreatedPayment, PaymentResult, RefundResult, ActionResult};

final readonly class Client
{
    private const BASE_URL = 'https://payments.athmovil.com/api/business-transaction/ecommerce';
    private Transport $transport;

    public function __construct(
        #[\SensitiveParameter] private string $publicToken,
        #[\SensitiveParameter] private ?string $privateToken = null,
        ?Transport $transport = null,
    ) {
        Validation::nonBlank($publicToken);
        if ($privateToken !== null) { Validation::nonBlank($privateToken); }
        $this->transport = $transport ?? new CurlTransport();
    }

    public function createPayment(Payment $payment): CreatedPayment
    {
        return new CreatedPayment($this->request('POST', '/payment', $payment->payload() + ['publicToken' => $this->publicToken]));
    }

    /** Optional bearer accommodates the incomplete findPayment header in upstream documentation. */
    public function findPayment(string $ecommerceId, #[\SensitiveParameter] ?string $authToken = null): PaymentResult
    {
        return new PaymentResult($this->request('POST', '/business/findPayment', [
            'ecommerceId' => Validation::nonBlank($ecommerceId), 'publicToken' => $this->publicToken,
        ], $authToken));
    }

    /** Call only after the customer confirms. This operation can debit the customer. */
    public function authorizePayment(#[\SensitiveParameter] string $authToken): PaymentResult
    {
        return new PaymentResult($this->request('POST', '/authorization', null, Validation::nonBlank($authToken)));
    }

    public function updatePhoneNumber(string $ecommerceId, string $phoneNumber, #[\SensitiveParameter] string $authToken): ActionResult
    {
        return new ActionResult($this->request('PUT', '/business/updatePhoneNumber', [
            'ecommerceId' => Validation::nonBlank($ecommerceId), 'phoneNumber' => Validation::phone($phoneNumber),
        ], Validation::nonBlank($authToken)));
    }

    public function cancelPayment(string $ecommerceId): ActionResult
    {
        return new ActionResult($this->request('POST', '/business/cancel', [
            'ecommerceId' => Validation::nonBlank($ecommerceId), 'publicToken' => $this->publicToken,
        ]));
    }

    public function refundPayment(string $referenceNumber, Amount $amount, ?string $message = null): RefundResult
    {
        if ($this->privateToken === null) {
            throw new \LogicException('A private business token is required for refunds.');
        }
        if ($amount->cents < 1) {
            throw new \InvalidArgumentException('Refund amount must be positive.');
        }
        $payload = [
            'referenceNumber' => Validation::nonBlank($referenceNumber), 'amount' => (string) $amount,
            'publicToken' => $this->publicToken, 'privateToken' => $this->privateToken,
        ];
        if ($message !== null) { $payload['message'] = Validation::text($message, 50); }
        return new RefundResult($this->request('POST', '/refund', $payload));
    }

    private function request(string $method, string $path, #[\SensitiveParameter] ?array $payload, #[\SensitiveParameter] ?string $authToken = null): array
    {
        $headers = ['Accept: application/json', 'Content-Type: application/json'];
        if ($authToken !== null) { $headers[] = 'Authorization: Bearer ' . Validation::nonBlank($authToken); }
        try {
            $body = $payload === null ? '' : json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        } catch (\JsonException) {
            throw new \InvalidArgumentException('Request contains invalid JSON data.');
        }
        $response = $this->transport->send($method, self::BASE_URL . $path, $headers, $body);
        try {
            $decoded = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            if ($response->statusCode < 200 || $response->statusCode >= 300) {
                throw new ApiException($response->statusCode, null);
            }
            throw new ProtocolException('ATH Movil returned invalid JSON; remote outcome is unknown.');
        }
        $code = is_array($decoded) ? ($decoded['errorcode'] ?? null) : null;
        // Only expose provider identifiers, never arbitrary response text.
        $safeCode = is_string($code) && preg_match('/^[a-zA-Z0-9_.-]{1,100}$/D', $code) ? $code : null;
        if ($response->statusCode < 200 || $response->statusCode >= 300) {
            throw new ApiException($response->statusCode, $safeCode);
        }
        if (!is_array($decoded) || !is_string($decoded['status'] ?? null)) {
            throw new ProtocolException('ATH Movil returned an invalid response envelope.');
        }
        if ($decoded['status'] === 'error') { throw new ApiException($response->statusCode, $safeCode); }
        if ($decoded['status'] !== 'success' || !array_key_exists('data', $decoded)) {
            throw new ProtocolException('ATH Movil returned an unrecognized response envelope.');
        }
        return $decoded;
    }

    public function __debugInfo(): array { return ['credentials' => '[REDACTED]']; }
}
