# AI Learning Phase 0 Findings

**Inspection scope:** Local workspace only (`D:\autoreply\autoreply-backend` and `D:\autoreply\autoreply-frontend`). No staging or production systems, databases, or provider APIs were accessed. No secrets, environment values, or customer data were read or reported. No code, configuration, database, or environment changes were made; this document is the requested Phase 0 deliverable.

## Outcome

**Phase 0: complete with blocking reliability issue. Do not start Phase 1 yet.** A provider/embedding exception can escape the inline knowledge retrieval performed in the live auto-reply job. This directly meets the handoff stop condition: knowledge embedding errors can prevent a customer reply. Address and verify an explicit fail-safe before adding learning behavior.

## 0.1 Provider configuration and embedding branches

- `app/Services/AiProviderConfiguration.php:10-35` recognizes `openai`, `claude`, `gemini`, and `groq`. For each provider it selects the configured stored key if active, otherwise the configured application key under the provider-specific config path. The report deliberately does not include key material.
- `app/Services/EmbeddingsService.php:14-16, 24-35` chooses the first configured key in priority order `openai → gemini → claude → groq`; it fixes output dimensionality to 768.
- `app/Services/EmbeddingsService.php:39-49, 57-76` configures OpenAI as `text-embedding-3-small`, Gemini as `gemini-embedding-001`, Claude as OpenAI's embedding URL/model, and Groq as `nomic-embed-text` at a Groq OpenAI-compatible endpoint. `embedChunk()` calls Gemini's native helper only for Gemini; every other provider goes through the OpenAI-compatible helper (`:70-75`). The source comments themselves acknowledge Claude has no embeddings API and Groq may not support this endpoint. Those branches are configured but successful provider compatibility was not tested against live APIs.
- Failure behavior: the provider helpers throw `AiProviderRequestException` for unsuccessful HTTP responses (`EmbeddingsService.php:108-110, 150-152`); `AiGateway::execute()` catches exceptions for billing reconciliation and rethrows (`AiGateway.php:191` onward). Thus this is not a “return null on provider failure” interface. `null` is returned for blank text or no configured key (`EmbeddingsService.php:59-60`).

## 0.2 Knowledge schema, hash, and statuses

- Chunks are in `business_knowledge_chunks`; the migration creates the chunk text and JSON embedding, without a content hash column: `database/migrations/2026_08_30_100325_create_business_knowledge_chunks_table.php`.
- File-level deduplication is in `business_knowledge_files.content_hash`, not on chunks. `database/migrations/2026_10_10_000018_add_content_hash_to_business_knowledge_files.php:12-38` adds nullable `char(64)`, hashes whitespace-normalized extracted text with SHA-256, skips assigning a duplicate within the same business, and creates unique `(business_profile_id, content_hash)`. Hash may remain null for duplicates.
- `business_knowledge_files.status` is a plain string with database default `active`; the migration adds no enum/check constraint (`database/migrations/2026_08_30_100451_add_status_to_business_knowledge_files_table.php:13-16`). Application code uses `processing`, `active`, and `failed` in `app/Jobs/ProcessKnowledgeFile.php:57-63, 121-135`; `app/Http/Controllers/Api/KnowledgeController.php:30-31, 113-130` also interprets stale `processing` and `failed` for retry. Therefore there is no database-enforced closed set of allowed statuses.
- No live or local database queries were performed in Phase 0, so row counts and existing hash/status distributions are not established by this audit.

## 0.3 Retrieval failure handling (stop condition)

- The live path is `app/Jobs/ProcessAutoReply.php:60` (`handle`). Its inline knowledge context closure starts at `:1924` and calls `EmbeddingsService::embedChunk()` at `:1931` before checking for an empty result at `:1933-1935`.
- There is no `try/catch` around that embedding call. The local catch at `:1947-1957` only covers the later bot knowledge assignment query; it does not cover embedding generation. The job has retry configuration, so an exception can fail/retry the whole reply job and ultimately leave the inbound message unanswered if retries do not succeed.
- This confirms the handoff's stop condition. **Do not proceed to Phase 1 until query-embedding failure is contained and tested so reply generation continues without semantic knowledge, and ingestion failures remain visibly/reportably failed.**

## 0.4 Legacy retry job dispatch

- `app/Jobs/ProcessAutoReplyWithRetry.php:22` defines the legacy retry job.
- Repository-wide identifier search across `app`, `config`, `routes`, `bootstrap`, `database`, and `scripts` found only the job class/log strings; it found no dispatch site for `ProcessAutoReplyWithRetry`.
- The dispatch sites found for inbound reply processing call `ProcessAutoReply::dispatch(...)`, including `app/Http/Controllers/WebhookController.php:604,1179`, `app/Http/Controllers/TikTokController.php:272`, `app/Http/Controllers/GmailController.php:416`, `app/Http/Controllers/TelegramController.php:245`, and `app/Http/Controllers/Api/WebChatController.php:271`.
- Conclusion: legacy job exists but no active application dispatch reference was found in the searched source trees. This is a source search, not proof about out-of-repository queue payloads already stored in an environment.

## 0.5 Feedback authorization

`app/Http/Controllers/Api/FeedbackController.php:17-38` validates feedback, derives accessible business IDs with `conversation_write`, scopes the target message through its conversation's `business_id`, returns not found for an inaccessible message, and accepts feedback only for AI messages. The submission endpoint is therefore business/conversation scoped in the code reviewed.

## 0.6 AI reply audit fields and privacy

`app/Services/AiReplyAuditService.php:12-52` records the conversation's authoritative business and conversation IDs, validated same-business bot/commerce connection IDs, agent/prompt versions, knowledge file/chunk IDs, provider/model, confidence, escalation decision/reason, and consent basis. The service comment states it does not copy prompts, customer text, or credentials; the fields written match that statement. Message content is stored separately in the message system and is outside this audit-table claim.

## Test-runner baseline

- Frontend command: `npm test` (`autoreply-frontend/package.json`, Vitest). Result: **7 test files failed, 14 passed; 9 tests failed, 58 passed (67 total)**. Failures included missing expected UI labels/options and `HTMLCanvasElement.prototype.getContext` not implemented under jsdom in `Dashboard3DVisual.tsx`.
- Backend command: `php artisan test --compact`. The suite emitted numerous failures (`⨯`) and then repeatedly hit PHP's 128 MB memory limit while parsing a PDF in `vendor/smalot/pdfparser/src/Smalot/PdfParser/Font.php:150`. The process did not produce a final PHPUnit summary in the captured output. Treat backend baseline as **failed/incomplete**, not a pass.
- These are baseline observations only. No tests were edited or rerun with changed settings.

## Recommendations (description only; no changes implemented)

1. Prioritize a narrow fail-open boundary around retrieval query embedding: record a sanitized failure code, skip semantic retrieval for that reply, and continue ordinary reply generation. Add isolated tests for provider exception, missing provider, and successful retrieval. Keep embedding ingestion failures visible as failed/retryable file processing rather than silently marking files active.
2. Validate provider/model compatibility in a mocked/local test suite. Remove or correct misleading Claude/Groq embedding fallback branches unless their configured endpoints genuinely support the requested embedding model and dimensionality.
3. Keep the legacy retry class out of the dispatch path unless there is an explicit migration plan; add a static regression check against accidental dispatch if the old job must remain.
4. Resolve the existing test baseline issues, especially the backend PDF parser memory exhaustion and frontend jsdom canvas setup, then rerun suites before any learning rollout.
5. Only after those checks pass, decide learning data retention, tenant isolation, consent, review/rollback, and evaluation requirements with the owner before implementation.

## Not verified

- Live provider availability, credentials, scopes, remote API compatibility, staging/production queue behavior, and database row distributions were not checked.
- No production action or deployment was performed.
