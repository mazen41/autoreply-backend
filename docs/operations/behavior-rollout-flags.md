# Per-business behavior rollouts

Safety behaviors that can pause AI or workflow replies are exposed under **Dashboard → Automation controls → Safety behavior rollouts**. Each toggle is scoped to the selected business and records the actor and timestamp in the audit log. New assignments are disabled until an owner or manager enables them.

| Flag | Behavior when enabled |
| --- | --- |
| `phase3_no_agent_fail_closed` | Pause AI and create a handoff when no eligible active agent is resolved, including an inactive agent pinned to a conversation. |
| `phase3_setup_verification_required` | Require a passing setup checklist before channel AI can be enabled. |
| `phase5_workflow_reply_safety` | Block workflow message actions while a human owns the conversation. Explicit AI-off is always enforced independently. |
| `phase5_sequence_takeover_guard` | Block sequence enrollment and sends while a human owns the conversation. |

Marketing consent and suppression checks remain enforced independently of these rollout toggles. They are compliance controls, not optional reply behavior. Order confirmation continues to require a previously displayed summary and customer confirmation.

Roll out one business at a time. Before enabling a flag, review the business's channel assignments and handoff recipients; after enabling it, monitor the handoff inbox and `monitor:operations` alerts. Disable the assignment to restore the previous behavior for that business. Do not change a global flag to roll out a behavior.
