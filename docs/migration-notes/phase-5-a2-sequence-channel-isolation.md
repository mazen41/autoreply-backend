# Phase 5 A2: sequence channel isolation

## Change

Sequence message steps send only through the conversation's own channel, after verifying that it belongs to the sequence business and is connected. Missing, mismatched, or disconnected channels pause the enrollment with `channel_disconnected` and open a high-priority handoff. Telegram sequences now read the channel's encrypted `access_token` and persist the provider message ID.

## Merchant impact

Messages will no longer silently move to another connected account of the same channel type. The enrollment pauses and appears in the human handoff queue until the conversation's own channel is available.

## Verification and rollback

`SequenceHardeningTest` verifies disconnected-account isolation, handoff creation, and Telegram token/provider-ID behavior. Migration `2026_10_10_000016_add_paused_status_to_sequence_enrollments` adds the paused enrollment state; rollback maps paused rows to stopped before removing that enum value. Reverting the code restores the prior channel fallback behavior and should be coordinated with the migration rollback.
