# Phase 8 N6: Handoff inbox and SLA

## Migration

Run `php artisan migrate` to add nullable resolution notes to handoff tasks. Existing tasks and due times are preserved.

## What merchants see

The dashboard shows the exact overdue open-task count. The Handoff inbox page supports open/resolved, priority, overdue, and customer/reason search filters. Managers and owners can assign active teammates, adjust priority and SLA due time, and save a resolution note when closing a task. Agents only see and modify tasks assigned to them. The owner may be selected as an assignee even when not represented by a team-membership row.

Routes include `PATCH /api/handoff-tasks/{taskId}` for priority and due time, `GET /api/handoff-tasks/assignees` for business-scoped assignable staff, and optional `resolution_note` on the existing resolve route. All operations remain scoped to channels owned by the explicitly selected business.

Focused verification: `php artisan test --filter=HandoffTaskFlowTest`.
