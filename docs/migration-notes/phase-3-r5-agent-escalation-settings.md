# Phase 3 R5 — Agent escalation settings

## Change

The agent wizard now exposes escalation keywords and assistant intent names alongside the existing confidence threshold. The API validates nested settings and saves them on the agent; `ProcessAutoReply` uses the agent's threshold and escalation configuration at runtime.

## Migration

No schema migration is required.

## Merchant-visible change

On the agent wizard's Persona step, merchants can enter comma or line separated keywords and intent names. Blank values retain business-level escalation settings.

## Tests

`php artisan test --filter='agent_escalation_settings_are_saved|agent_keyword_and_confidence_configuration_drives_runtime_escalation'`

## Rollback

Revert the wizard fields and nested API validation. Existing stored agent escalation settings remain readable by the current runtime.
