# Phase 4 O7: Provider-backed outbound status

Workflow and sequence messages remain pending until their provider send
returns. Accepted sends are marked sent; rejected or unsupported sends are
marked failed with a reason. Meta image failures are reported as a partial
failure rather than hidden behind a successful text send, and Meta provider
message IDs are stored on the message metadata. WhatsApp delivery callbacks
update the unified inbox message status. The inbox displays failure reasons.

No schema change is required.

Verification: `php artisan test --filter='OutboundMessageStatusTest|SequenceExecutionTest|WorkflowEngineRefactorTest'`.

Rollback: revert the status behavior and inbox display changes together. Older
outbound rows keep their existing status values.
