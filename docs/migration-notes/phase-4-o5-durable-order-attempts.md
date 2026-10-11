# Phase 4 O5: Durable order attempts

Order creation now records a durable attempt before contacting a commerce
provider and stores the external order ID immediately after a successful
provider response. A retry reuses a completed result. An attempt with an
ambiguous network outcome stays pending and suppresses another create request,
so it must be reconciled rather than blindly retried.

## Migration

Run `php artisan migrate` to create `commerce_order_attempts`. Rollback with
`php artisan migrate:rollback --step=1` only if this migration is the latest
and no attempt records need to be retained.

## Provider limitation

Salla's documented order-create endpoint does not specify a provider
idempotency key. Shopify documents REST idempotency only for selected payment
endpoints, not order creation. The application therefore enforces durable
local deduplication and does not claim provider-side exactly-once behavior.

Verification: `php artisan test --filter='CheckoutOrderFlowTest|SallaOrderStateRecoveryTest'`.

Rollback: deploy the prior code only after confirming no in-flight pending
attempts exist; retain the table until those attempts are reconciled.
