# Phase 3 R4 — Durable human handoffs

## Change

Escalation, hard-keyword, after-hours, AI-failure, missing-agent, and failed-order paths create or reopen one durable handoff task before sending a human-follow-up message. Tasks have a configurable SLA due time, in-app notifications, and optional email notifications. Authenticated endpoints list, assign, and resolve tasks within the selected business.

## Migration

Run `php artisan migrate` to add `handoff_tasks.due_at` and the `handoff_task` notification type. Both migrations include rollback logic.

## Merchant-visible change

Open handoffs appear in the inbox and dashboard with SLA/overdue status. Team members can resolve a task or open its conversation. Email notifications are controlled by `HANDOFF_EMAIL_NOTIFICATIONS` (default `true`); the SLA is controlled by `HANDOFF_SLA_MINUTES` (default `30`).

## Tests

`php artisan test --filter=HandoffTaskFlowTest`

## Rollback

Set `HANDOFF_EMAIL_NOTIFICATIONS=false` to stop handoff email alerts immediately. Roll back only after removing the UI/API use of task due times and the handoff notification type.
