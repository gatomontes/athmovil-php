# Changelog

## 0.1.0-alpha.1 — 2026-09-14

First public development preview, licensed under MIT.

### Included

- PHP 8.2+ Composer client for ecommerce creation, status lookup, authorization, phone updates, cancellation, and refunds.
- Typed results, decimal amounts, payment/item metadata, input validation, and explicit error handling.
- HTTPS transport with certificate verification, no redirects or automatic retries, and rejection of simulated credential prefixes.
- Stateful local simulator that builds responses from the submitted items and metadata, with fictional Test Payer customer fields.
- Confirmation, expiry, cancellation, partial/full refunds, and rejection/timeout-before/timeout-after scenarios.
- Per-business in-memory/file storage, atomic file updates, configurable clock, repeatable fixtures, reset, redacted capture export, and bounded polling.
- CLI simulation example and browser checkout demo under `examples/checkout/`.
- PHP 8.2–8.5 CI with 38 client/simulation tests and HTTPS/checkout HTTP smoke tests.

### Limitations

- Live ATH Móvil integration has not been tested; no live credentials were available.
- This alpha is for development/evaluation and is not a stable or production-validated payment integration.
- Simulator fees, dates, identities, errors, and transition rules are testing assumptions where documented, not proof of provider behavior.
- Public APIs may change before a stable release. Packagist registration is separate from this GitHub release.
