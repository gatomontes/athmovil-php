# Validation

The package includes offline client/simulator tests, a runnable simulation demo, and a PHP 8.2–8.5 GitHub Actions matrix. Tests use fabricated local data. The concurrency case launches two PHP processes against one private local file store.

Verified implementation: `af794dd9d0ee2c9e4124ab0390b4d6e9f3f54454`.

[GitHub Actions run 34862705882](https://github.com/gatomontes/athmovil-php/actions/runs/34862705882) passed on PHP 8.2, 8.3, 8.4, and 8.5 (Ubuntu):

| Check | Result |
| --- | --- |
| Composer strict validation and installation | Passed on all four PHP versions |
| PHP syntax checks | Passed |
| Client and simulation tests | 38 tests, 0 failures per PHP version |
| Checkout/refund demonstration | Completed with original metadata and Test Payer |
| Real cURL adapter against local HTTPS fixture | Trusted certificate accepted; untrusted certificate/hostname mismatch rejected; redirects not followed; HTTP errors returned without retries |

The authoring container has no PHP/Composer; execution evidence comes from GitHub CI. The HTTPS fixture uses only loopback, not ATH Móvil. Live provider integration NOT RUN.

The suite covers endpoint requests, decimal precision, validation, metadata, typed responses, request-derived simulation, fictional refund customers, transition rules, partial refunds, timeout before/after effects, capture redaction, business isolation, persistence, rollback/corruption handling, competing refunds, repeatable fixtures, reset, and bounded polling.

Before a stable release, perform explicitly authorized live acceptance for create/find/customer confirmation/authorize, merchant and customer cancellation, expiration, phone update, refund, and merchant/order/receipt reconciliation. Resolve the documented upstream ambiguities and select a license. Simulation demonstrates application flow, not provider acceptance.

No real ATH Móvil API requests, charges, refunds, or Packagist registration are part of package development.
