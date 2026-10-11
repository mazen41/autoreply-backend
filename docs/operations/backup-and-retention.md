# Database backup operations

`backup:database` writes application-encrypted `.sql.enc` artifacts. Encryption uses Laravel's `APP_KEY`; changing that key without retaining the old key makes existing backup files unreadable. The command writes to `BACKUP_DISK` (default `local`), under `BACKUP_PATH` (default `backups`), and deletes backup artifacts older than `BACKUP_RETENTION_DAYS` (default 7 days). On local storage it attempts mode `0700` on the containing directory and `0600` on each backup file. Remote disks still receive ciphertext and should also enforce private access and provider-side encryption.

## Production configuration check

The repository cannot verify the production `.env`, mounted volume, cloud bucket policy, or actual object lifecycle. Operators must verify these before relying on the scheduled backup:

1. Set `BACKUP_DISK` to a private, durable backup disk. The default local disk is under `storage/app/private`; it is on the application host and is not a disaster-recovery target by itself.
2. Set `BACKUP_PATH` and `BACKUP_RETENTION_DAYS` to the approved destination and retention period. The shipped retention default is seven days.
3. Keep the matching `APP_KEY` backed up separately in the organization's secret manager. Do not put it beside the encrypted dump.
4. Run `php artisan backup:database` in staging, verify the artifact ends in `.sql.enc`, decrypt it only in a controlled restore environment, and complete a restore rehearsal.
5. Confirm disk/bucket IAM permits only the backup operator and application identity, and configure provider-side encryption and lifecycle retention for remote storage.

The scheduler runs the command daily at 02:00. Backups contain customer and credential data; never copy them to public storage or attach them to support tickets.

## Scheduler and alerts

Scheduled events use an expiring overlap lock so a stalled invocation cannot block future runs indefinitely. Scheduled command failures are reported through the configured operations alert email. `monitor:operations` checks queue backlog, webhook signature failures, and per-channel send failure rates; `monitor:failed-jobs` reports failed queue jobs. Set `OPERATIONS_ALERT_EMAIL` and the alert thresholds in the environment, then verify alert delivery from staging before relying on notifications.

The scheduled Gmail poll dispatches a queue job; its scheduler callback reports dispatch failures, while failures after dispatch are covered by failed-job monitoring.
