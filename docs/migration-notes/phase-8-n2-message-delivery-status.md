# Phase 8 N2 — Message send and delivery status

## Changes

- New outbound messages begin as `queued`; `send_status` remains a string so the inbox can represent queued, sent, delivered, read, failed, and blocked states without changing the legacy delivery enum.
- WhatsApp Cloud callbacks retain Meta's exact state in `send_status`. The legacy `delivery_status` column is kept at `delivered` for `read`, because its existing enum does not include `read`.
- Stale callbacks cannot downgrade `read`, `failed`, or `blocked` messages. Provider failures retain a bounded reason from the Meta error payload.
- Send and delivery status changes are broadcast through the existing message event contract. The payload includes `send_status`, `delivery_status`, and `error_details`; the inbox upserts by message ID.
- Manual sends persist blocked/failed records and show provider or policy reasons. Cloud-window and unofficial-connection restrictions are represented as `blocked`.
- Proactive marketing consent rejections now create blocked records. Proactive WhatsApp is blocked unless the approved-template flow is configured. Proactive Gmail uses the existing Gmail-backed channel send service rather than marking a placeholder as sent.
- Proactive jobs do not automatically retry an outbound send because the provider calls do not have idempotency keys and retries after an ambiguous timeout could duplicate marketing messages.

## Database and rollout

No schema migration is required. Existing `delivery_status` values retain their current enum meanings; new exact states are carried in the existing `send_status` string column. Deploy backend and frontend together so the inbox understands `queued`, `read`, and `blocked`. Existing rows with `pending` remain valid and are displayed as pending.

## Verification

- `WhatsAppCloudApiTest`, `OutboundMessageStatusTest`, `ProactiveConsentGateTest`, and `InboxRealtimeTest`: 30 tests passed (126 assertions) after the final N2 edits.
- Frontend `npx tsc --noEmit`: passed.
- PHP syntax checks and `git diff --check`: passed. Git emitted only existing line-ending normalization warnings.

## Limits

The external provider remains authoritative for delivered/read confirmation. A `sent` state means the provider accepted the request, not that the recipient received it. Gmail does not provide the same delivery/read callback semantics as WhatsApp Cloud. Provider sends with ambiguous network outcomes are marked failed for operator visibility and are not automatically retried by the proactive-message job.
