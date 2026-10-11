# Phase 5 A3: sequence consent and automation safety

## Changes

- Enrollment now requires same-business conversation ownership, no active human takeover, and marketing opt-in. Missing consent and suppressions block both automatic and manual enrollment; Gmail conversations resolve consent using `sender_email` and the canonical `email` consent channel.
- No-reply timers use the same conversation-aware consent check, stop if a human has taken over, and default `allow_reentry` to false. A previous completed or stopped enrollment can be re-entered only when that setting is explicitly enabled.
- Customer replies stop no-reply sequences and other sequences whose `settings.stop_on_reply` is true. Pending executions are marked skipped. The sequence editor now exposes both `stop_on_reply` and `allow_reentry` settings, and the API validates their types.
- Unknown, empty, or malformed condition rules fail closed and condition settings are validated at save/activation. Pausing a sequence pauses current enrollments; activation resumes them with new execution records.
- Sequence channel `email` maps to connected `gmail` accounts and uses the Gmail channel adapter, preserving conversation threading and provider delivery status rather than sending through a generic Laravel mail transport.

## Verification

Focused sequence and consent tests cover blocked enrollment, Gmail consent identity, re-entry defaults, stop-on-reply cancellation, human takeover, pause/resume, channel isolation, and fail-closed conditions. The broader sequence regression completed successfully with 85 tests and 270 assertions. Existing PHPUnit doc-comment metadata deprecation warnings remain unrelated.

## Operational notes

The new paused enrollment status requires migration `2026_10_10_000016_add_paused_status_to_sequence_enrollments`. The migration rollback maps paused enrollments to stopped before removing the status value. Existing contacts without opt-in will no longer enroll in marketing sequences; capture consent before enrolling them.
