# Salla Address Flow — Phase 1 Audit (existing code)

Date: 2026-10-12. Scope: `D:\autoreply\autoreply-backend` (main tree; `.claude\worktrees\*` ignored).
Status: static code audit. Phase 0 (live Salla test-store verification) and server-side Nominatim checks are NOT done yet; items needing them are marked **[needs live check]**.
Not yet read: `app/Jobs/ProcessAutoReply.php` (240 KB — call sites of the checkout/geocode/order methods), `SallaWebhookJob`, existing tests' assertions.

## 1. Relevant code map

| Concern | Location |
|---|---|
| OAuth URL, token exchange/refresh, API client | `app/Services/SallaService.php` (`getAuthorizationUrl`, `apiCall`, `apiCallForChannel`) |
| City catalog sync | `SallaService::syncCityCatalog`, `app/Console/Commands/SyncSallaCities.php` (`salla:cities:sync {country} {channelId?}`), table `salla_cities` |
| Free-text → Salla city/address fields | `SallaService::resolveShippingAddressForChannel` |
| Order payload + courier + customer | `SallaService::buildCanonicalOrderPayload`, `createOrderForChannel` |
| Field extraction from chat + required fields + confirmation state | `app/Services/OrderCheckoutService.php` (`extractAndMergeState`, `computeFieldStatus`, `updateConfirmationState`) |
| Labelled field parsing (block/district/postal/building) | `app/Services/SallaAddressParser.php` |
| Geocoding | `OrderCheckoutService::geocodeDeliveryAddress` → `config('services.nominatim.endpoint')` |
| Conversation state | `conversations.checkout_state` (JSON) |
| Human handoff | `HandoffTask` model, `HandoffTaskService::openForConversation`, `HandoffTaskController`, `config/handoff.php` (`HANDOFF_SLA_MINUTES=30`) |

## 2. Findings vs. the plan (ordered by severity)

### Critical — orders can be built from guessed data
1. **Block is fabricated.** `resolveShippingAddressForChannel` takes the text after `district|block|neighborhood|حي|منطقة|شارع|فصل|فيصل|طريق`; note `شارع`/`فيصل`/`طريق` are street words, not blocks. If nothing matches, it uses **the first 3 words of the address** as `block`. This violates "never guess".
2. **`street_number` = first 1–5 digit number anywhere** (`\b(\d{1,5})\b`) — can capture a postal code, phone fragment, or apartment number.
3. **No district resolution at all.** There is no `salla_districts` table, no call to `GET /cities/{city}/districts`, and `ship_to` never contains a district ID. `SallaAddressParser` extracts a `district` string but nothing consumes it.
4. **`address` and `address_line` are both the entire free-form text**, and `ship_to` also sends a non-contract `address` key. **[needs live check]**
5. **Coordinates are treated as optional in one place and required in another.** `buildCanonicalOrderPayload` comment says optional, but `estimate-rate` is called with `$coordinates['lat'] . ',' . $coordinates['lng']` without a null check → when coords are missing this sends `geocode=","` (plus a PHP warning). Courier lookup therefore runs on invalid input.
6. **Required-field set is too small for Egypt.** `computeFieldStatus` requires: `full_name, phone, address, email, postal_code, building_number, full_address`. Not required: district, block, street_number, geo_coordinates. `short_address`/`additional_number` are required only for Saudi numbers. An Egyptian order can reach payload construction without block/district/coords.

### High — geocoder
7. **Geocoding is city-only by design.** It queries Nominatim with `city=<name>&country=<name>&limit=1` and returns status `found` with the city centroid. That is exactly the "broad city-center result treated as exact delivery location" the plan forbids, and there is no `ambiguous` outcome. Results are cached 30 days per city.
8. **City extraction is a hardcoded 35-city list** (plus "last comma-separated part" fallback), separate from Salla's city catalog. Two city-resolution implementations exist (this one and the `salla_cities` matcher).
9. **Likely failure causes to check first [needs live check]:**
   - `NOMINATIM_BASE_URL` is **not in `.env.example`**; if unset on the server, the method logs "skipped because no private endpoint is configured" and returns `unavailable` every time.
   - Config cache stale after `.env` change (`php artisan config:clear`).
   - Self-hosted Nominatim dataset may not include Egypt (e.g. Saudi/GCC extract only), or Arabic city names (`الجيزة`) may not match.
   - The 1 req/sec `Cache::lock` throttle is unnecessary for a private instance and can add `block(10)` waits under load (cache store is `database`).
   - Root cause must come from `storage/logs` ("Nominatim address lookup failed/unavailable" entries incl. `http_status`) and a direct `curl` to the endpoint on srv1817907.

### High — API client / reliability
10. **`apiCall` discards the response body.** Any non-2xx becomes `Exception("API call failed: 422")`. Salla's field-level validation errors are lost, so 422 recovery (plan §10) is impossible today.
11. **No retries/backoff** for 5xx/timeouts (only 401→refresh), **no explicit timeout** on `Http` calls, and **no idempotency key or timeout reconciliation** for `POST /orders` in `SallaService`. (Some dedupe exists in checkout state — `order_fingerprint`, `confirmation_summary_message_id`, `failed_order_attempts` — but its behaviour in `ProcessAutoReply` is unverified.)
12. **Courier selection:** only rates with a `cod` service are accepted, first match wins; payment is hardcoded `cod` / `pending_payment`. No handling for stores without COD. Receiver `country_code` defaults to `SA` when the phone prefix is unknown.
13. **Product resolution by name** substring over the first 20 products, with "single product → use it" fallback.

### Medium — privacy/logging
14. `Log::warning` writes the **full `checkout_state`** (name, phone, address) when `salla_product_id` is missing, and logs `address` in two other places. Conflicts with plan §12.

### Medium — scopes
15. `getAuthorizationUrl` already requests `offline_access settings.read orders.read_write customers.read_write products.read metadata.read`, and the token response's `granted_scopes` is logged (`[SALLA OAuth Token Response]`). Stores connected **before** `metadata.read` was added still lack it; token refresh cannot add scopes (the code already comments this). Missing-scope 401s are detected via message text. **[needs live check: whether `metadata.read` is the correct scope for `/cities/{id}/districts` and `/countries/{id}/cities`; which connected channels have it.]**
16. `SALLA_API_BASE_URL` exists in `.env.example`, but `SallaService` hardcodes `https://api.salla.dev/admin/v2` and ignores it.

## 3. Reusable pieces (don't duplicate)
- `salla_cities` (per-channel, unique `(channel_id, country_code, salla_city_id)`) + `syncCityCatalog` (paginated, upsert, resumable, stale-row cleanup, refuses to overwrite with empty page). Extend the pattern for districts rather than inventing a new one.
- `conversations.checkout_state` + `updateConfirmationState` / `isExplicitOrderConfirmation`: existing confirmation workflow (collecting_info → confirmation_pending → confirmed → order_placed). Add address-state keys here instead of a new entity unless the JSON becomes unmanageable.
- `HandoffTask` + `HandoffTaskService`: notifications (in-app broadcast, email to owner/team, business `handoff_notification_email`), SLA `due_at`, automation event. Gaps for the review queue: one task per conversation only (`firstOrCreate` by `conversation_id`), no structured payload (missing fields / validation result / pending-order ref), no resume action, SLA is global config (30 min), not per store.
- Channel-scoped tokens and per-channel catalogs already isolate multiple stores.

## 4. Gaps to build (matches plan phases)
- `salla_districts` table + `syncDistrictCatalog` (per channel + city, paginated) — Phase 3.
- `SallaAddressResolver` replacing `resolveShippingAddressForChannel` internals (word-boundary city match scoped to country, district match scoped to city, ambiguity outcomes, no first-3-words fallback) — Phase 4.
- Geocoder client with `success|not_found|ambiguous|unavailable` and street-level (not city-level) queries — Phase 2.
- `SallaOrderReadinessValidator` + per-store rules; remove ad-hoc Saudi/Egypt branching from `computeFieldStatus` — Phase 6.
- `apiCall` to expose status + body + parsed validation errors, add timeout/backoff, idempotent submit + reconcile — Phase 8.
- Block states (`provided | confirmed_absent | unknown`) in `checkout_state` — Phase 5/7.

## 5. Phase 0 checklist still required (needs the test store)
1. Read the stored `granted_scopes` / re-run OAuth on the test channel; call `GET /admin/v2/countries`, `/countries/{id}/cities`, `/cities/{id}/districts` and record HTTP status per call.
2. Controlled `POST /orders` matrix for Egypt (fields listed in plan §2.2), recording status + validation errors for each omission.
3. Server: `NOMINATIM_BASE_URL` value, `curl` health/search for a Giza address, dataset coverage, recent log entries.

## 6. Open question for ProcessAutoReply
Need to locate: where `geocodeDeliveryAddress` is called, where `createOrderForChannel` is invoked, how `failed_order_attempts` / `order_fingerprint` guard duplicates, and where handoff is triggered on order failure.
