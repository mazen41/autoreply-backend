# Bot + Multi-Account Architecture — Implementation Log

## Environment

- Backend: D:\autoreply\autoreply-backend (Laravel 11 / PHP 8.3.33, PHPUnit 11.5.56)
- Frontend: D:\autoreply\autoreply-frontend (Next.js 16.2.7 / TypeScript)
- Both repos: git status clean on `main` at start. Backend has worktrees
  (worktree-comment-automation-controller, worktree-fix-escalation,
  worktree-fix-escalation-v2, worktree-fix-fetch-sender-name,
  worktree-migration-update, worktree-vectorized-wishing-dahl) — untouched.
  Frontend has one worktree (comment-automation-nav) — untouched.
- No composer commands run in this session, per explicit instruction. Vendor
  directories were already present and used as-is.

## PHASE 0 — Baseline

- `vendor\bin\phpunit --no-coverage` run twice before any change.
  - Both runs: 122 tests / 330 assertions, 100% executed.
  - Run 1: 4 errors, 1 failure, 2 skipped.
  - Run 2: 3 errors, 1 failure (different subset of failing tests).
  - **The suite is order/state-dependent (flaky)** — different tests fail
    between runs with no code changes in between. Observed pre-existing
    failure causes:
    - `GuzzleHttp\Exception\ConnectException` to `http://localhost:8080` —
      `EvolutionApiService` (WhatsApp) trying to reach a local Evolution API
      instance that isn't running in this environment.
    - `SequenceEndToEndTest` — "No connected channel found for sending message".
    - `CheckoutOrderFlowTest` — `Undefined array key "address"` / `"status"`.
    - `SallaOrderStateRecoveryTest::it_persists_checkout_state_across_turns_and_recovers_when_api_fails`
      — `Failed asserting that null is not null.`
  - These are recorded as pre-existing baseline noise, not touched.
- Frontend: `npx tsc --noEmit` → clean, exit code 0, no type errors.
- `npx next build` not yet run (will run before/after frontend-phase changes).

## PHASE 1 — Verified integration bug fixes

### 1. Salla webhook channel-resolution bug — FIXED

**File:** `app/Jobs/SallaWebhookJob.php`

**Root cause (verified against actual code, not just the audit):**
- OAuth (`app/Http/Controllers/ChannelController.php::callbackSalla`) stores
  the merchant/store identity in `channels.page_id`, upserting on
  `[user_id, type, page_id]`.
- `channels.store_id` is a real column (added in
  `2026_08_07_000001_add_salla_fields_to_channels_table.php`) but is **never
  written anywhere in the codebase** — confirmed via full-repo search for
  `'store_id' =>` writes. Only read/logged, never persisted.
- The general webhook handler in `SallaWebhookJob::handle()` was querying
  `Channel::where('type','salla')->where('store_id', $storeId)`, which could
  never match a real row. `handleAppInstalled()` in the same file already
  correctly resolves via `$data['merchant']` + `page_id` — the fix brings the
  general path in line with that existing, working pattern.
- `SallaWebhookController::handle()` merges Salla's top-level webhook fields
  (which include `merchant`) into the data array passed to the job, so
  `merchant` is available for all event types, not just `app.installed`.

**Fix:** resolve the channel via `$this->data['merchant']` (falling back to
`store_id` for defensiveness, not as the primary identifier) against
`channels.page_id`, matching the OAuth data model exactly. No new identifier
introduced.

**Tests:** `vendor\bin\phpunit --filter Salla` → 28 tests, 80 assertions.
Only the two pre-existing baseline failures above remain (unrelated to
channel resolution — both are Salla *order-state/API-retry* assertions, not
webhook routing). All `SallaWebhookTest` signature/dispatch tests pass.

**Follow-up worth doing (not yet done):** add an explicit regression test
asserting that a webhook with `merchant` = Channel B's `page_id` updates
Channel B's metadata and not Channel A's, now that multiple Salla stores per
user will be a normal case post-Bot-migration.

### 2. TikTok connect bug + webhook routing — FIXED

**File:** `app/Http/Controllers/TikTokController.php`

**Verified typo bug:** in `callback()`, `$tikTokUser` (capital T-o-k, never
defined) was read instead of `$tiktokUser` (the actual variable). In PHP this
silently evaluates to `null` rather than erroring, so `display_name` always
fell back to `$username`, and — critically — `open_id` **always fell back to
`''`**. This means `channels.page_id` (and `metadata.open_id`) was `''` for
*every* TikTok connection ever made, for every user. Multiple TikTok accounts
for the same user would silently collide on the same channel row
(`updateOrCreate` keyed on `[user_id, type, page_id='']`), and no real open_id
was ever persisted. Fixed by using the correct variable.

**Webhook account-identifier verification:** checked TikTok's actual public
Webhooks API docs (developers.tiktok.com/doc/webhooks-events). It defines
exactly four events (`authorization.removed`, `video.upload.failed`,
`video.publish.completed`, `portability.download.ready`), and every payload
uses the documented envelope `{ client_key, event, create_time, user_openid,
content }` — `user_openid` is the verified account identifier, delivered at
the top level, matching `channels.page_id` once the typo above is fixed.

The existing `webhook()` method did **not** handle this real envelope at all —
it only handled a `$request['comment']` shape that has no counterpart
anywhere in TikTok's documented public API (TikTok has no comment-webhook
event). Per the audit's own instruction ("do not guess the account
identifier... mark clearly instead of inventing behavior"), I:
- Added proper handling for the real, documented envelope, resolving the
  channel via `user_openid` → `page_id`, and implemented
  `authorization.removed` (marks the channel disconnected with the
  documented `reason` code persisted to metadata). The other three events are
  logged with the resolved channel but have no side effect yet — none was
  specified and none is safe to guess.
- Left the old `comment` handling in place, unmodified, but wrapped in a
  clearly labeled comment explaining it does not correspond to any real
  TikTok webhook event and cannot be "fixed" without inventing a payload
  shape. Flagging for product decision: TikTok's public API has no
  comment-reply automation; this would need a different mechanism (e.g.
  TikTok's invite-only Comment Kit) if the product actually needs it.

**Tests:** added `tests/Feature/TikTokWebhookTest.php` (new — no TikTok tests
existed before). 2 tests / 5 assertions, both passing: verifies
`authorization.removed` resolves and updates only the channel matching
`user_openid` (not a sibling channel for the same user), and that an unknown
`user_openid` is handled without error.

### 3. Telegram account routing — FIXED

**Files:** `app/Http/Controllers/TelegramController.php`, `routes/api.php`,
new `app/Console/Commands/ResyncTelegramWebhooks.php`

**Verified bug:** the webhook URL was `/api/telegram/webhook/{userId}` —
it encodes *only* the user, not which bot. If a user connects two Telegram
bots, Telegram registers each bot's webhook to the literal same URL
(nothing stops that on Telegram's side), and the handler picked
`Channel::where('user_id', $userId)->first()` — the first connected Telegram
channel, regardless of which bot the update actually came from. Exactly the
"first connected channel for user" anti-pattern called out in the audit.

**Fix (backward-compatible, preserves existing customers):**
- Added a new route `/api/telegram/webhook/{userId}/{channelId}` that names
  the exact Channel in the URL itself.
- `connect()` now creates/updates the Channel row **first**, then registers
  the bot's webhook against the new channel-specific URL. `setWebhook()`
  does the same.
- The **old** URL (`/api/telegram/webhook/{userId}`, no channel id) is kept
  registered as a working route (existing bots that haven't re-synced yet
  still receive updates) but its resolution logic changed: it now only
  auto-resolves when the user has **exactly one** connected Telegram bot
  (in which case it's provably unambiguous, so behavior for those existing
  single-bot customers is unchanged). If a user has multiple bots and a
  message arrives on the old URL, the handler now explicitly refuses to
  guess and logs an actionable error, instead of silently misrouting to the
  wrong bot as before.
- New Artisan command `telegram:resync-webhooks` (with `--dry-run`) lets you
  re-register every existing connected Telegram bot's webhook against its
  own new channel-specific URL, using each channel's own stored token. It's
  read of DB / POST to Telegram only — never mutates a Channel row — and is
  safe to run repeatedly. **Not run automatically** — per "show me the
  migration plan," this is ready to run whenever you approve it; running it
  is what makes existing multi-bot customers fully exact instead of relying
  on the "exactly one bot" legacy fallback.

**Tests:** new `tests/Feature/TelegramWebhookRoutingTest.php`, 3 tests / 7
assertions, all passing:
1. New URL routes to the exact channel when a user has two bots.
2. Legacy URL still works unchanged for a user with exactly one bot.
3. Legacy URL with an ambiguous (2-bot) user creates no conversation at all
   (proves it refuses to guess rather than misrouting).

### 4. Shopify & WooCommerce order lookups — FIXED
- `ShopifyController::getOrders()` and `WooCommerceController::getOrders()` updated to disambiguate multi-store order lookups by checking `channel_id` or requiring explicit `channel_id` when multiple stores are connected.

### 5. WhatsApp & Gmail Multi-Account — FIXED
- `WhatsAppController.php`: Refactored `connect()`, `status()`, `getQrCode()`, `disconnect()`, `sendMessage()`, `getMessages()`, `getInstance()` to use `resolveInstance($request, $userId)` supporting explicit `channel_id` or `instance_name`.
- `GmailController.php`: Updated `updateOrCreate()` to include `page_id` (`$email`) so multiple Gmail accounts per user are supported.

## PHASE 2 — Bot Architecture Implementation

### 1. Database Schema & Migrations — COMPLETED
- `2026_09_29_000001_create_bots_table.php`: `bots` table created (`id`, `business_profile_id`, `name`, `status`, `ai_provider`, `ai_model`, `ai_instructions`, `ai_tone_style`, `reply_style`, `ai_confidence_threshold`, `escalation_config`).
- `2026_09_29_000002_create_bot_channels_table.php`: `bot_channels` pivot table (`bot_id`, `channel_id`).
- `2026_09_29_000003_create_bot_knowledge_assignments_table.php`: `bot_knowledge_assignments` table (`bot_id`, `business_knowledge_file_id`, `channel_id` nullable).
- `2026_09_29_000004_add_bot_id_to_conversations_table.php`: Nullable `bot_id` column added to `conversations`.

### 2. Models & Relationships — COMPLETED
- `App\Models\Bot`: Belongs to `BusinessProfile`, `hasMany` `BotKnowledgeAssignment`, `belongsToMany` `Channel`, `belongsToMany` `BusinessKnowledgeFile`, `hasMany` `Conversation`.
- `App\Models\BotKnowledgeAssignment`: Pivot model linking `Bot`, `BusinessKnowledgeFile`, and optional `Channel`.
- `App\Models\BusinessProfile`: Added `bots()` relationship.
- `App\Models\Channel`: Added `bots()` relationship.
- `App\Models\BusinessKnowledgeFile`: Added `botAssignments()` and `bots()` relationships.
- `App\Models\Conversation`: Added `bot_id` to `$fillable` and `bot()` relationship.

### 3. Vector Knowledge Search & AutoReply Engine — COMPLETED
- `VectorSearchService.php`: Updated `search()` method to support `$allowedFileIds` array filtering while maintaining tenant isolation (`business_profile_id`).
- `ProcessAutoReply.php`:
  - Safe Bot resolution: reads snapshot from `conversation.bot_id` or channel pivot without arbitrary `first()` fallback. Logs warning on ambiguous multi-bot assignments.
  - Snapshot persistence: stores `conversation->bot_id` on first resolution.
  - Precedence: Bot AI settings (`ai_instructions`, `reply_style`, `ai_tone_style`) override `BusinessProfile` default fallback settings.
  - Knowledge scoping: filters vector search to files explicitly assigned to the Bot (`channel_id IS NULL` for bot-wide, or `channel_id = $channel->id` for channel-specific). If no Bot is assigned, falls back to legacy business-wide knowledge retrieval.
- `routes/channels.php`: Added missing private broadcast channel authorization rule for `inbox.{userId}` to fix WebSocket auth errors.

## Tests Executed

- `tests/Feature/BotArchitectureTest.php`: 3 tests / 10 assertions (100% passing).
  1. `test_bot_knowledge_filtering_shared_vs_channel_specific`: Verifies shared vs channel-specific knowledge file scoping.
  2. `test_business_tenant_isolation_in_vector_search`: Verifies multi-business tenant isolation in vector search.
  3. `test_legacy_unassigned_knowledge_fallback_when_no_bot_assigned`: Verifies legacy business-wide knowledge fallback.

