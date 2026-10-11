# Phase 5 A1: sequence execution recovery

## Change

Sequence executions now retain one outbound message row and its provider message ID before the execution is considered complete. A retry reuses provider-accepted message evidence, recent processing work and lock contention are requeued, and abandoned processing records become reclaimable after the worker/lock timeout. Each enrollment receives monotonic execution indexes and visit counts; execution stops at 50 visits with `max_step_executions_reached` recorded on the enrollment.

## Merchant impact

Duplicate dispatches are less likely to duplicate customer messages. Sequence loops stop visibly instead of running without a bound. Queue contention may delay a step while it is retried.

## Verification and rollback

`SequenceExecutionTest` and `SequenceHardeningTest` pass, including accepted-message recovery, stale processing, lock release, monotonic visit indexes, and the 50-execution cap. Migration `2026_10_10_000015_add_execution_indexes_to_sequence_steps` backfills per-enrollment indexes and can be rolled back. Rollback: revert the model/job/service changes and roll back this migration after the application is stopped from creating indexed executions.
