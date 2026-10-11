# Phase 3 R7: Agent-specific channel commerce routing

## Change

Commerce routing now belongs to the `bot_channels` assignment. This lets two
agents that share one Instagram, Facebook, or WhatsApp channel route their
own conversations to different enabled stores. Conversation context remains
sticky once resolved.

## Migration

`2026_10_10_000013_add_ecommerce_connection_to_bot_channels.php` adds a
nullable foreign key from `bot_channels.ecommerce_connection_id` to `channels`.
It copies an existing channel-wide default into a Bot/channel assignment only
when that store is connected, enabled for that Bot, and in the same business.
The old channel column remains available for legacy conversations without a
resolved Bot; no existing data or credentials are deleted.

## Application behavior

- Agent create/update validates each route against the Bot's enabled connected
  stores and the business-owned channel assignment.
- Route changes are saved atomically with the Bot/channel pivots.
- The commerce resolver uses a Bot-specific route before the Bot default and
  ignores the global channel default when a Bot is present.
- The Bot wizard loads saved routes from the Bot/channel pivot.

## Verification

Focused feature tests cover route precedence, route isolation for two agents
sharing one channel, validation, and transaction rollback.
