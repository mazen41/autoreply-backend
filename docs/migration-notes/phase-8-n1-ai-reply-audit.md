# Phase 8 — N1 AI reply and automation audit trail

## Implemented

- Added `ai_reply_audits`, uniquely keyed by message, with tenant, conversation, bot/version, resolved commerce connection, knowledge file/chunk IDs, prompt version, actual AI provider/model, confidence, escalation outcome/reason, and consent basis.
- Records are written for standard AI replies, the product-browse fallback, after-hours replies, the legacy AI retry path, and sequence sends. Sequence rows state the marketing opt-in basis and identify the automation step instead of implying an LLM generated the text.
- Knowledge source identifiers are recorded; retrieved document text, prompts, customer messages, and credentials are not copied into audit rows.
- Added `GET /api/messages/{messageId}/ai-audit`. It requires the explicitly selected business and `write` access (owner/manager); messages from another business are concealed as 404.
- Added the inbox “Why did the bot say this?” disclosure. It displays audit details when opened and explains the owner/manager permission boundary. It does not expose the system prompt.
- `AI_PROMPT_VERSION` selects the prompt version label and defaults to `v1`.

## Migration and rollout

Apply `2026_10_10_000023_create_ai_reply_audits_table` before deploying the backend code, then deploy the matching frontend. Existing replies are not backfilled; audit data is recorded for newly created replies/actions.

## Verification

- `AiReplyAuditAccessTest`, `SequenceExecutionTest`, and `AuditContinuationBackendTest`: 22 tests passed, 108 assertions.
- Owner and manager access, agent denial, cross-business concealment, source-ID persistence, and sequence consent provenance are covered.
- The daily analytics fixture pins timestamps and timezone to UTC midday; this prevents a full-suite run crossing a business-day boundary from making the test flaky.
- `npx tsc --noEmit` passed after the inbox changes.

## Limitations

- The legacy retry processor reports its actual OpenAI request or its keyword-rule fallback; it does not claim a confidence score that its provider path does not return.
- Audit rows do not reconstruct historical replies from before the migration.
