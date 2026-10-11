# Agent routing and handoff migration note

The migration adds one handoff task record per conversation so a conversation routed to a person has a durable, idempotent inbox task. Existing conversations and routing assignments are preserved.

Fail-closed behavior for channels with no assigned active agent is feature-flagged and defaults off. Merchants must opt in by setting `AGENT_ROUTING_FAIL_CLOSED=true`; when enabled, such messages stop before AI processing, mark the conversation for a human, and create or reopen its handoff task. Existing invalid or inactive agent assignments already fail closed and now also create the task record.
