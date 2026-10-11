# Phase 5 A4 — Workflow runtime correctness

## Changes

- Normalize workflow-builder trigger, condition, and action payloads into the engine schema.
- Persist source-event IDs and enforce one execution per workflow, conversation, and source event with a unique index.
- Add business-timezone scheduled triggers, Arabic-aware keyword boundaries, workflow-condition evaluation, outbound eligibility gates, partial-failure recording, and replay of failed actions only.
- Connect newly opened/reopened handoff tasks to `escalation_triggered` workflows.
- Add replay endpoint: `POST /api/workflows/{id}/executions/{executionId}/replay`.

## Merchant-visible changes

- Scheduled workflows run from the scheduler in business-local time.
- Failed workflow executions expose per-action results and can replay failed actions after the normal outbound eligibility checks pass.
- A configured escalation trigger now fires when a handoff task is first opened or reopened.
- Workflow message actions are blocked when AI is disabled, a human has taken over, or marketing consent is absent.

## Migration

Run `php artisan migrate`. Migration `2026_10_10_000017_add_workflow_event_idempotency` adds `event_id`, assigns unique legacy IDs to prior rows, and adds the event uniqueness constraint.

## Verification

- `WorkflowEngineRefactorTest`, `WorkflowTest`, and `HandoffTaskFlowTest`: 48 passed, 142 assertions (at A4 completion).
- Frontend `npx tsc --noEmit`: passed.

## Rollback

Rollback only the A4 migration with `php artisan migrate:rollback --step=1` if it is the latest migration. Remove/disable the scheduled command and replay route in the same code rollback. Existing event IDs are disposable execution-deduplication metadata.
