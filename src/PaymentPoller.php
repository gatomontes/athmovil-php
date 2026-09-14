<?php
declare(strict_types=1);

namespace AthMovil;

use AthMovil\Result\PaymentResult;

/** Bounded status polling only. Never authorizes or retries a failed request. */
final readonly class PaymentPoller
{
    private \Closure $pause;
    public function __construct(private Client $client, ?callable $pause = null)
    {
        $this->pause = $pause === null ? static fn (int $milliseconds) => usleep($milliseconds * 1000) : \Closure::fromCallable($pause);
    }

    /** Return first non-OPEN result, or last OPEN result after maxAttempts. */
    public function waitForConfirmation(string $id, #[\SensitiveParameter] ?string $authToken = null, int $maxAttempts = 10, int $intervalMilliseconds = 1000): PaymentResult
    {
        if ($maxAttempts < 1 || $maxAttempts > 1000 || $intervalMilliseconds < 0 || $intervalMilliseconds > 60000) {
            throw new \InvalidArgumentException('Use 1–1000 attempts and a 0–60000 millisecond interval.');
        }
        for ($attempt = 0; $attempt < $maxAttempts; ++$attempt) {
            if ($attempt > 0) { ($this->pause)($intervalMilliseconds); }
            $result = $this->client->findPayment($id, $authToken);
            if ($result->paymentStatus() !== 'OPEN') { return $result; }
        }
        return $result;
    }
}
