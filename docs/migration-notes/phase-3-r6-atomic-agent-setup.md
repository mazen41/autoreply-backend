# Phase 3 R6 — Atomic agent wizard save

## Change

Agent create/update endpoints now persist the agent, enabled stores, channel assignments, primary responder, knowledge assignments, and channel store routing inside one database transaction. Routing references are validated against the selected business, the channels assigned to the agent, and the agent's enabled connected stores. The wizard submits routing in the same request and no longer follows a successful save with independent channel PATCH requests.

## Migration

No schema migration is required.

## Merchant-visible change

The wizard reports validation errors without leaving a partially created or updated agent. Store routing is saved with the rest of the setup.

## Tests

`php artisan test --filter='BotTenantAuthorizationTest'`

Includes invalid-route validation and a simulated database failure proving that the whole create transaction rolls back.

## Rollback

Revert the wizard's `channel_store_routing` payload and restore the prior route PATCH flow together with the backend request support.
