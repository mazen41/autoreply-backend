# Phase 8 N7 — Automation run controls

## Merchant-visible behavior

Owners and automation managers can pause/resume all queued campaign, email campaign, sequence, proactive, and workflow work for a business from **Dashboard → Run controls**. A business-wide pause is persisted and audited. Queued sends check the pause before delivery and are released for another check in 60 seconds; after resume, pending recipients continue. Campaigns and email campaigns can also be paused individually. Existing sequence pause/activate and workflow toggles now write audit events.

Ordinary inbound AI service replies and human inbox replies are not stopped by this marketing/automation kill switch.

## Schema/API

- Migration `2026_10_10_000029_add_automation_run_controls.php` adds nullable pause timestamps to businesses, campaigns, and email campaigns; rollback removes those columns.
- `GET/PUT /api/businesses/{businessId}/automation-run-control` reads/updates the business kill switch.
- `POST .../campaigns/{campaignId}/pause|resume` and `POST /api/email-campaigns/{id}/pause|resume` control individual campaign runs.

Pending queue work is held at 60-second intervals and retryable for 14 days; merchants should resume or explicitly relaunch work held longer than that window.
