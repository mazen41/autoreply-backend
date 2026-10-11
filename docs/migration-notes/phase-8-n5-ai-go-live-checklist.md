# Phase 8 N5: AI go-live checklist

## Migration

Run `php artisan migrate` to add consent-policy acknowledgement, a business handoff notification email, and the setup fingerprint for successful test replies. Existing businesses do not lose live replies; older test markers without a matching fingerprint must be regenerated before AI can be enabled again through the channel controls.

## What merchants see

The Channels page checklist now requires a connected channel, an active agent assigned to one, a configured supported provider, business or agent instructions/knowledge, a successful test reply matching the current setup, consent-policy acknowledgment, and a valid human handoff email. The handoff email receives escalation notices in addition to existing owner/team notifications. Test replies now require business write access.

Settings are available at `GET/PUT /api/businesses/{businessId}/go-live-settings`; the same checklist is returned by `/api/ai/setup-verification` and enforced before enabling channel AI replies. The test-reply fingerprint covers agent/provider/instruction settings, assigned channels, business AI settings, and knowledge file status/version.

Focused verification: `php artisan test --filter=AiSetupVerificationTest`.
