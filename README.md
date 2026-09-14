# ATH Móvil PHP

A framework-independent Composer wrapper for ATH Móvil ecommerce payments, with a local simulator for complete checkout development.

**Unofficial development version.** Not endorsed by Evertec. The simulator is local software, not an ATH Móvil testing environment. No live payment compatibility has been claimed. See [validation](docs/validation.md) and [upstream contract notes](docs/api-contract.md).

## Install from GitHub

Requirements: PHP 8.2+, JSON, and `ext-curl` for real HTTP requests. Simulation needs no cURL or business credentials. There are no third-party runtime dependencies.

Until a release is registered on Packagist, add the repository to your application's `composer.json`:

```json
{
  "repositories": [{"type": "vcs", "url": "https://github.com/gatomontes/athmovil-php"}],
  "require": {"gatomontes/athmovil-php": "dev-main"}
}
```

Then run `composer update gatomontes/athmovil-php`. Merge these keys with existing configuration. For package development:

```sh
git clone https://github.com/gatomontes/athmovil-php.git
cd athmovil-php
composer install
composer test
php examples/simulation.php
```

The 38-test suite, example, and loopback HTTPS smoke test pass on PHP 8.2–8.5; see [the recorded validation run](docs/validation.md). To run the optional transport smoke test locally, use `python3 tests/transport-smoke.py` after `composer install`; it also requires Python 3 and OpenSSL.

## Complete a simulated checkout

```php
<?php
declare(strict_types=1);

use AthMovil\{Amount, Payment};
use AthMovil\Simulation\Simulator;

$simulation = new Simulator();
$client = $simulation->client();

$created = $client->createPayment(new Payment(
    total: Amount::fromDecimal('25.00'),
    phoneNumber: '7875550100',
    metadata1: 'ORDER-1001',
    metadata2: 'STORE-MANATI',
    items: [
        ['name' => 'Coffee', 'description' => 'Ground coffee', 'quantity' => 2,
         'price' => '8.00', 'tax' => null, 'metadata' => 'SKU-COFFEE'],
        ['name' => 'Mug', 'description' => 'Ceramic mug', 'quantity' => 1,
         'price' => '9.00', 'tax' => null, 'metadata' => 'SKU-MUG'],
    ],
    subtotal: Amount::fromDecimal('25.00'),
    tax: Amount::fromDecimal('0.00'),
));

$simulation->confirmPayment($created->ecommerceId()); // Simulated customer action
$paid = $client->authorizePayment($created->authToken());
$refund = $client->refundPayment($paid->referenceNumber(), Amount::fromDecimal('5.00'));

echo $paid->metadata1();               // ORDER-1001
echo $refund->refund()['name'];        // Test Payer
echo $simulation->exportHistory();     // Redacted requests, responses, state changes
```

Responses derive from the submitted items, quantities, prices, taxes, and metadata. The simulator preserves invoice values; it does not recalculate or silently correct your totals. It uses integers for refund accounting and emits numeric JSON amounts at the response boundary to match upstream examples.

The documented refund customer fields contain `Test Payer`, `(787) 555-0100`, and `test.payer@example.com`. The submitted phone remains in local request/state records. Payment-status responses follow the documented business/transaction shape, which does not include payer name/email. Simulation flags remain in the capture envelope, outside provider response bodies.

## Client operations

| Method | Result | Purpose |
| --- | --- | --- |
| `createPayment(Payment)` | `CreatedPayment` | Create the ticket and transaction token |
| `findPayment($id, $authToken = null)` | `PaymentResult` | Retrieve payment state |
| `authorizePayment($authToken)` | `PaymentResult` | Submit a customer-confirmed payment |
| `updatePhoneNumber($id, $phone, $authToken)` | `ActionResult` | Update the pending request's phone |
| `cancelPayment($id)` | `ActionResult` | Request cancellation |
| `refundPayment($reference, Amount, $message = null)` | `RefundResult` | Refund a specified amount |

Useful accessors include `ecommerceId()`, `authToken()`, `paymentStatus()`, `isConfirmed()`, `isCompleted()`, `referenceNumber()`, `total()`, `metadata1()`, `metadata2()`, `items()`, `refund()`, `originalTransaction()`, and `isRefundCompleted()`. See each result class for its supported methods. `isCompleted()` checks ecommerce status only; use `isRefundCompleted()` for refunds. Explicit `data()` access returns the decoded provider payload.

Metadata is supported at both levels: `metadata1`/`metadata2` on the payment and `metadata` on every item. Character limits and nullable item metadata are validated. No structured metadata is auto-encoded: send strings that fit the provider limits.

## Simulate across web requests

```php
use AthMovil\Simulation\{Simulator, FileStore};

$simulation = new Simulator(
    businessId: 'store-one',
    store: new FileStore('/private/local-development/athmovil'),
);
$client = $simulation->client();
```

Use the same private directory and business ID on each request. Keep ticket IDs and simulated authorization tokens with your application's test orders. `FileStore` serializes changes with a per-business file lock and atomically replaces state files. In-memory storage is the default and lasts only as long as the store object.

Different business IDs isolate payments, tokens, refund balances, faults, and captures. This is test-data namespacing, not authentication against hostile processes that can read the same directory. Do not expose simulator control methods as public web endpoints. This adapter is for trusted local development, not distributed production storage.

## Control outcomes

```php
use AthMovil\Simulation\{Fault, Operation};

$simulation->confirmPayment($id);
$simulation->cancelPayment($anotherId); // Customer cancellation
$simulation->expirePayment($thirdId);

$simulation->failNext(Operation::Authorize, Fault::Reject, 'BTRA_0005');
$simulation->failNext(Operation::Authorize, Fault::TimeoutBefore);
$simulation->failNext(Operation::Refund, Fault::TimeoutAfter);
```

A fault applies once to the next matching operation for that business. Setting another fault for the same operation replaces the previous one. Other operations are unaffected. `TimeoutBefore` changes no transaction; `TimeoutAfter` runs the operation and loses its response. Inspect status/history to reconcile the outcome. Fault codes and state rules model scenarios, not an assertion of every provider behavior.

Authorization requires confirmation. Completion cannot happen twice. Cancellation/expiration only apply to pending requests; phone changes require OPEN. Refunds require completed payments and cannot exceed the remaining balance. Expiration is evaluated when the next client/control operation touches the simulator. The package does not provide automatic refund idempotency: two valid partial refunds remain two separate refunds.

## Clock, repeatability, capture, reset

```php
use AthMovil\Simulation\{Simulator, FrozenClock};

$clock = new FrozenClock();
$simulation = new Simulator(clock: $clock, seed: 'repeatable-test');
$clock->advance(600);
$entries = $simulation->history();
$json = $simulation->exportHistory();
$simulation->reset();
```

Fresh stores with the same business, seed, and clock produce repeatable fixtures. Default IDs use a random epoch. Reset clears only the selected business and starts a new random epoch, invalidating old tokens even when an initial seed was supplied. Existing file state takes precedence over a newly supplied seed.

Captures contain submitted items/metadata, request/response envelopes, before/after payment states, and controls. A lost response is distinguished from the simulator's internal server result. Credential fields and authorization headers are redacted. Captures still contain the supplied phone, item descriptions, and arbitrary metadata; use synthetic data and review exports before sharing. Raw local state contains simulated tokens and submitted order data. `var/` is gitignored for optional local captures.

## Optional bounded polling

```php
use AthMovil\PaymentPoller;

$result = (new PaymentPoller($client))->waitForConfirmation(
    $id, $authToken, maxAttempts: 10, intervalMilliseconds: 1000,
);
```

Returns the first non-OPEN result or the last OPEN result after the bound. It never authorizes; errors propagate without retries. Missing/unknown statuses stop polling rather than assuming success. The optional pause callback supports fast tests. The bound is a request count; overall elapsed time also includes HTTP request durations.

## Real payments

Instantiate `new AthMovil\Client($publicToken, $privateToken)` in server-side code. Refunds require the private token; other methods do not. This selects the included HTTPS cURL transport. Keep configuration explicit; simulation is never an automatic fallback from production.

Persist the created ticket/token against the correct local order, obtain customer confirmation, authorize explicitly, and verify completion, reference, merchant/order association, and total before fulfillment. The wrapper does not implement your order database, concurrency locks, or fulfillment policy. A ticket or confirmation alone is not payment completion.

`examples/create-payment.php` contacts production only with `ATHMOVIL_RUN_LIVE=yes` plus your configured public token and customer phone. It creates a request and does not authorize it. Read the example before running it.

## Error handling and HTTP behavior

- `InvalidArgumentException`: invalid local input.
- `LogicException`: missing configuration or invalid simulator control.
- `ApiException`: HTTP failure or provider error; exposes HTTP status and provider code.
- `TransportException`: no reliable response; an effect may already have happened.
- `ProtocolException`: unusable response; an effect may already have happened.

Do not automatically repeat authorization/refund after an uncertain outcome, including server errors. Reconcile first. The cURL adapter verifies TLS, does not follow redirects, makes one attempt, and blocks simulated credential prefixes. Its HTTP timeout is separate from the customer's payment window. Errors omit raw provider messages/bodies. Keep `zend.exception_ignore_args=On` in production and avoid indiscriminate logging of `data()`; it may contain credentials or personal information.

Custom `Http\Transport` implementations must preserve TLS verification and single-attempt/no-redirect behavior. The simulator implements this same interface but holds no network transport. Custom adapters are responsible for their own credential handling.

## Frameworks, scope, licensing

Register `Client` in Symfony or Laravel's service container and inject configuration. The core requires no framework. Dedicated adapters, checkout UI, webhooks, reporting, and B2C disbursements are outside this version.

The repository is a development package, not a tagged stable release. Licensing remains undecided; Composer is marked `proprietary` until the owner chooses a license. No Packagist registration or distribution license grant is implied.
