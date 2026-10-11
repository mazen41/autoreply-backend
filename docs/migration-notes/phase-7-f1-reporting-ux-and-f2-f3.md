# Phase 7 — Frontend contracts, classification, and TikTok

## Implemented in this tranche

- Added `GET /api/orders`, scoped to the explicitly selected business through `ActiveBusiness`. Search, provider/status filters, bounded pagination, channel/store labels, and the first line-item name are returned from stored commerce orders. Orders are never loaded by provider across businesses.
- Added authenticated `GET /api/business` and `POST /api/business` handlers, also scoped to the selected business and authorized through the business membership service. The settings UI no longer starts with invented merchant details and now displays save/load failures.
- Added optional `description`, `website`, and `address` fields to `business_profiles` because the existing settings form already edits them. Migration: `2026_10_10_000022_add_business_profile_details`.
- Removed the Classification navigation item and replaced its page with a clear disabled state. The previous page showed fabricated default config/results when the API was missing or failed; it no longer calls nonexistent APIs or displays fake classifier output.
- Disabled new TikTok OAuth connection and callback completion; the Channels page labels TikTok messaging unavailable, prevents connect and AI enable actions, and keeps existing accounts disconnectable. AI jobs skip TikTok before reply generation; the shared outbound sender marks TikTok sends unsupported.
- Changed Google/Facebook social sign-in callbacks to redirect with a 60-second, single-use exchange code instead of a Sanctum token. The frontend exchanges the code by POST and removes it from browser history before redemption.
- Removed the bearer token from inbox media URLs. The UI fetches attachments with the Authorization header and uses a temporary object URL; the media API route now lives behind Sanctum and selected-business authorization.
- Replaced the reports PDF exporter with mPDF so Arabic and other Unicode text can be rendered. The export records its local date range and timezone, caps detail rows at 100 with a visible truncation notice, escapes user-provided values, and uses a private temporary directory. CSV export uses the same business-local range and neutralizes spreadsheet formula cells.
- Added `POST /api/businesses/{businessId}/campaigns/audience/preview`. It reports the selected sending account, eligible/matched totals, consent and suppression exclusions, expired Meta 24-hour windows, and WhatsApp Cloud/template readiness. The UI labels this as an estimate; delivery jobs re-check eligibility at send time.
- Email audience preview now reports the configured effective From name/address and separates no consent, opted out, suppressed, and automated sender exclusions. Sequence enrollment history shows the actual channel account and persisted stop/failure reasons; exports include the account and reason.
- Aligned messaging campaign delivery consent checks with preview through `ConsentGate::checkConversation`, including Gmail's email identifier. Consent, connection, and provider window checks are repeated at send time.
- Corrected the older AnalyticsDashboard labels: the duplicate `total`/`new` cards are no longer presented as distinct measures, median first-reply time displays seconds or “No data,” unsupported average-resolution data is omitted, and the selected date range/timezone plus status-count definition are visible.

## Verification

- `CampaignConsentGateTest`, `EmailCampaignConsentGateTest`, `SequenceEnrollmentServiceTest`, and `SequenceExecutionTest`: 27 tests passed, 94 assertions after the campaign/sequence preview changes.
- `ReportsMetricDefinitionsTest`, `OrdersAndBusinessProfileApiTest`, `AuditSecurityAndSequenceTriggersTest`, and `OAuthLoginExchangeTest`: 25 tests passed, 116 assertions, including streamed PDF output and Arabic text extraction.
- `npx tsc --noEmit`: passed after the campaign/sequence UI changes. `composer validate --no-check-publish`: valid.

## Operational action

Run `php artisan migrate` to add the business profile detail fields before deploying the updated Settings page. TikTok is intentionally disabled because this integration does not currently have a supported public customer-messaging path.

Deploy backend and frontend together for social-login code exchange; older frontends expect a `token` query parameter and will not consume the new callback format.

## Remaining verification / follow-up

- Add and wire a frontend-to-backend API route contract check in CI. Frontend and backend are separate repositories; CI needs a deliberate way to check out both repositories or consume a shared generated route manifest.
- The analytics and inbox controls were reviewed against their current API fields/routes; this work has focused tests but has not been browser-tested against a live deployment.
- Apply the added business-profile migration before deploying the updated Settings page. Deploy backend and frontend together for the social-login exchange-code flow and campaign audience-preview UI.
- Production OAuth/provider behavior, campaign delivery, and Arabic PDF appearance still need deployment-level verification with real connected accounts and recipient data.
