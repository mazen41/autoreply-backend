# Phase 8 N4: Data rights and retention

## Migration

Run `php artisan migrate` to add three per-business retention settings and the `data_rights_requests` table. Defaults are 365 days for messages, 30 days for media, and indefinite consent retention (`NULL`). The migration is reversible and does not delete existing business data.

## Operations

- `GET/PUT /api/businesses/{businessId}/data-retention` reads/updates the selected business policy. Message retention accepts 30–3650 days; media accepts 1–3650; consent retention accepts `null` or 30–36500.
- `POST /api/businesses/{businessId}/data-exports` queues a ZIP export. Poll `/api/businesses/{businessId}/data-rights-requests`; download completed archives with `/api/businesses/{businessId}/data-exports/{requestId}/download`. Archives expire after seven days and are stored on the private local disk.
- `DELETE /api/businesses/{businessId}/customers/{customerId}/data` with `{ "confirm": true }` erases local customer profile, notes, conversations, messages, private media, consent records, and legacy WhatsApp messages. Synced commerce orders remain for accounting with customer fields redacted. Suppression rows remain so erased contacts cannot be re-added to marketing sends. The response and request history contain an ID-only receipt.
- Scheduled `media:expire-inbound` applies each business's media retention period. `data:apply-retention` purges messages past each business's message period, expires configured consent records, and removes expired export archives. Both run daily in the scheduler.

## Runtime requirements and checks

The backend PHP runtime needs the `zip` extension for ZIP exports. Configure a functioning queue worker so export requests complete asynchronously. Use private local storage or adapt the export disk to the deployment's private object storage before running multiple app hosts. Run the existing Laravel scheduler every minute as usual.

Focused verification: `php artisan test --filter=BusinessDataRightsTest`. The migration is covered by `RefreshDatabase` feature tests; do not point tests at production.
