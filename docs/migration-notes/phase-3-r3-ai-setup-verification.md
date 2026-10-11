# Phase 3 R3 — AI setup verification

## Change

Adds a readiness checklist for a connected channel, an active agent assigned to a connected channel, configured supported AI credentials, business knowledge or instructions, and a successfully generated test reply. A successful `/api/knowledge/test` call records the test timestamp. Enabling channel AI is rejected until every check passes; disabling AI remains available at any time.

## Migration

Run `php artisan migrate` to add nullable `business_profiles.ai_setup_tested_at`. Rollback with `php artisan migrate:rollback --step=1` only when rolling back this migration is safe for the release sequence.

## Merchant-visible change

The authenticated `GET /api/ai/setup-verification` endpoint returns each check, readiness, and missing checklist labels. New channels already default to AI off. Merchants must complete verification before enabling replies.

## Tests

`php artisan test --filter=AiSetupVerificationTest`

## Rollback

Roll back the migration only if no application version depends on the timestamp column. If an emergency bypass is required, deploy an explicitly reviewed code change rather than editing production data.
