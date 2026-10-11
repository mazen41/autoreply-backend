# Phase 9 — Scheduler and operations alerts

Scheduled work now has bounded overlap-lock expiry, including the Gmail poll dispatch. Scheduler command failures and Gmail poll dispatch failures report through the configured operations notifier; queued Gmail job failures remain covered by failed-job monitoring. Operations monitoring checks queue backlog, webhook signature failures, and outbound failure rates per channel.

Merchant-visible change: none. Operators should configure `OPERATIONS_ALERT_EMAIL` and thresholds, verify alert delivery in staging, and complete the backup restore rehearsal described in `docs/operations/backup-and-retention.md`.

Rollback: revert the scheduler callback change to restore the prior Gmail dispatch failure behavior. The overlap lock remains bounded in either version. No database migration is required.

Validation: `OperationalMonitoringTest` verifies scheduled lock bounds, failure callback registration, and operational alert behavior.
