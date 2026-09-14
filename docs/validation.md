# Validation

The package includes offline client/simulator tests, a runnable simulation demo, and a PHP 8.2–8.5 GitHub Actions matrix. Tests use fabricated local data. The concurrency case launches two PHP processes against one private local file store.

Status at preparation: PHP/Composer unavailable in the authoring container; GitHub CI execution pending. Live provider integration NOT RUN.

The suite covers endpoint requests, decimal precision, validation, metadata, typed responses, request-derived simulation, fictional refund customers, transition rules, partial refunds, timeout before/after effects, capture redaction, business isolation, persistence, rollback/corruption handling, competing refunds, repeatable fixtures, reset, and bounded polling.

Before a stable release, perform explicitly authorized live acceptance for create/find/customer confirmation/authorize, merchant and customer cancellation, expiration, phone update, refund, and merchant/order/receipt reconciliation. Resolve the documented upstream ambiguities and select a license. Simulation demonstrates application flow, not provider acceptance.

No real ATH Móvil API requests, charges, refunds, or Packagist registration are part of package development.
