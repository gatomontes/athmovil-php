# API contract notes

Source: https://github.com/evertec/ATHM-Payment-Button-API (reviewed 2026-09-14; upstream changelog ends at 1.2.2 in the retrieved document).

Base: `https://payments.athmovil.com/api/business-transaction/ecommerce`.

| Operation | Verb and suffix | Authentication sent |
| --- | --- | --- |
| Create | POST `/payment` | Public token in JSON |
| Find | POST `/business/findPayment` | Public token in JSON; optional bearer |
| Authorize | POST `/authorization` | Transaction bearer; empty body |
| Phone update | PUT `/business/updatePhoneNumber` | Transaction bearer |
| Cancel | POST `/business/cancel` | Public token in JSON |
| Refund | POST `/refund` | Public and private tokens in JSON |

Documentation discrepancies require live acceptance:

- Find's header example is incomplete. Optional transaction bearer is exposed rather than inventing credentials.
- Confirmation is spelled `CONFIRM` in JSON and `CONFIRMED` in prose. Preserve the returned string.
- Timeout example and table disagree. Apply the table's 120–600 seconds.
- Metadata/items required flags contradict surrounding prose. Send those keys, allowing empty strings/list; acceptance of empties remains unverified.
- Amounts appear as both numbers and strings. Send normalized decimal strings, as supported by request examples, without float arithmetic.
- Phone format is conservatively restricted to ten ASCII digits based on examples. International formatting is not normalized.

Provider limits may evolve; this draft validates payment total 1.00–1500.00, metadata 40 characters, refund message 50 characters. Merchant/card limits still apply remotely. The wrapper does not invent tax rules or recalculate invoices; order arithmetic belongs to the integrating application.

The official document states no testing environment exists. This package therefore has no sandbox hostname or sandbox credentials. Tests use fabricated local responses. B2C disbursements are a separate API and are not implemented.

## Simulator fidelity and deliberate assumptions

- Create returns only the documented ticket/token fields; find/authorize return the documented ecommerce transaction fields. Refund returns refund/originalTransaction sections with the documented customer fields. Test Payer is used consistently there; payer fields are not invented on payment-status responses.
- Metadata and item data derive from the request. Optional omitted subtotal/tax use zero in simulated responses; submitted values are retained, even when totals differ from line items. No optional strict invoice arithmetic checker is included in this version.
- Money is held in decimal strings/integer cents internally. JSON numeric response values mirror upstream examples; downstream PHP json_decode consequently produces floats where appropriate.
- Fee is deliberately zero and completed netAmount equals the submitted total. These are simulator defaults, not current provider pricing. The date clock is UTC and daily transaction IDs are synthetic.
- Confirmation uses CONFIRM; expiration and cancellation use CANCEL. Simulator rejection codes and transition choices are approximations for testing, not comprehensive provider contract evidence.
- The documented refund envelope includes original metadata/items; the refund subobject itself has no metadata fields. Preserve that distinction.
- Synthetic IDs carry sim- prefixes. Simulation markers and captured lost-response diagnostics are outside provider payloads.
