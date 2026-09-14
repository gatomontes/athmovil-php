# Local checkout demo

A small PHP web app that uses this package's public client and simulator APIs. It includes an editable three-line cart, payment/item metadata, customer confirmation, authorization, receipts, partial/full refunds, phone updates, cancellation, expiry, failure scenarios, and a capture viewer/export.

## Start it

From the repository root, in PowerShell, Bash, or another shell:

```sh
composer install
php -S 127.0.0.1:8080 -t examples/checkout/public
```

Open **http://127.0.0.1:8080**. Keep the terminal running; press Ctrl+C to stop. Requirements: PHP 8.2+, JSON and sessions, and writable local storage. No API tokens, cURL extension, database, JavaScript build, or external assets are required for the app.

Only the `public/` directory is served. App/session/simulator files live outside the document root. The application refuses execution outside PHP's loopback development server and rejects foreign Host headers. Use it as a local developer example, not a hosted checkout.

## Walk through a payment

1. Edit the default Coffee/Mug items, prices, quantities, taxes, and metadata. A blank item name omits that row. Blank item tax/metadata becomes null. The demo submits totals exactly as entered; it does not recalculate the invoice.
2. Click **Create simulated payment**. A new HTTP request loads its OPEN state from disk.
3. Click **Confirm as Test Payer**. This uses the simulator's customer control.
4. Select an authorization scenario, then click **Authorize payment**. Normal authorization returns a receipt. The example compares payment ID, total, both payment metadata fields, completion status, and a nonempty receipt reference with its stored order before displaying a matched receipt.
5. Submit a partial refund. The refund receipt shows **Test Payer**, `(787) 555-0100`, and `test.payer@example.com`. Refund totals and receipts remain visible after refresh or server restart.
6. Inspect the captured requests/responses or download their JSON. Submitted items and metadata are preserved.

Customer information is displayed as an explicitly fictional demo identity. Payer fields occur in the documented refund payload; the example does not invent them in payment-status responses. This demo's reconciliation is illustrative, not a production fulfillment system.

## Try uncertain outcomes

Authorization and refunds each offer successful, rejected, timeout-before, and timeout-after scenarios. When a response is lost after processing, the simulator commits the effect and the application does not retry it. The next page load checks the payment again; the capture also shows the internal server result. Recovered simulated refund receipts are read from capture history through the simulator's public API—this is development instrumentation, not a production refund recovery endpoint.

Forms use a one-use CSRF token and PHP session locking. Replaying an old form is rejected, so a double-click cannot accidentally repeat a refund in this example. A form from another tab may expire after an action; refresh that tab to obtain a current token.

## Persistence and reset

By default, private local data is stored under the gitignored `var/checkout/` directory:

- `sessions/`: PHP session files, browser order associations, and simulated transaction tokens.
- `simulator/`: the simulator's file state and locks.

Each browser session gets an independent simulated business. A second browser/private window cannot see or modify the first session's orders. Retain the cookie and data directory to resume after restarting the PHP server. Use **Reset this session** to clear that session's payments and captures. To remove all demo data, stop the server and remove `var/checkout/`.

For automated testing or an alternate private location, set `ATHMOVIL_DEMO_STORAGE` before starting the server. Keep the directory outside the document root. It is a storage setting only: the demo has no live mode and does not read ATH Móvil credentials.

Use fictional inputs. Exported histories redact credential fields, but preserve supplied phone numbers, item descriptions, and metadata. Raw local state contains simulated tokens; do not commit or share it casually.

## Verify the web flow

```sh
composer test
python3 tests/checkout-smoke.py
```

The HTTP smoke test requires Python 3, starts its own temporary PHP loopback server, and tests separate HTTP requests, restart persistence, session isolation, CSRF/replay rejection, metadata escaping, confirmation, lost responses, refunds, cancellation, expiry, and capture exports. It makes no provider calls. CI runs it alongside the existing suite.

Composer autoload still exposes only `src/`; this app is never started during installation. The demo is included in the repository for explicit local execution.
