<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\HandoffTask;
use App\Models\TeamMember;
use App\Services\ActiveBusiness;
use Illuminate\Http\Request;

class HandoffTaskController extends Controller
{
    public function index(Request $request, ActiveBusiness $activeBusiness)
    {
        $business = $activeBusiness->resolve($request, permission: 'conversation_read');
        $request->validate([
            'status' => ['sometimes', 'in:open,resolved'],
            'priority' => ['sometimes', 'in:low,normal,high,urgent'],
            'overdue' => ['sometimes'],
            'search' => ['sometimes', 'string', 'max:100'],
        ]);
        $query = HandoffTask::query()
            ->whereHas('conversation', fn ($q) => $q->whereIn('channel_id', $business->channels()->select('id')))
            ->with(['conversation:id,business_id,channel_id,sender_name,sender_id,status,escalation_reason', 'conversation.channel:id,type,page_name', 'assignee:id,name']);

        $membership = TeamMember::query()->where('business_id', $business->id)->where('user_id', $request->user()->id)->where('is_active', true)->first();
        if ($membership?->role === 'agent') $query->where('assigned_to', $request->user()->id);

        if ($request->filled('status')) {
            $query->where('status', $request->string('status')->toString());
        }
        if ($request->filled('priority')) {
            $query->where('priority', $request->string('priority')->toString());
        }
        if ($request->filled('search')) {
            $search = trim((string) $request->query('search'));
            $query->where(function ($tasks) use ($search): void {
                $tasks->where('reason', 'like', '%' . $search . '%')
                    ->orWhereHas('conversation', fn ($conversation) => $conversation->where('sender_name', 'like', '%' . $search . '%')
                        ->orWhere('sender_id', 'like', '%' . $search . '%'));
            });
        }
        if ($request->boolean('overdue')) {
            $query->where('status', 'open')->whereNotNull('due_at')->where('due_at', '<', now());
        }

        return response()->json($query->latest()->paginate(50));
    }

    public function resolve(Request $request, int $taskId, ActiveBusiness $activeBusiness)
    {
        $business = $activeBusiness->resolve($request, permission: 'conversation_write');
        $task = HandoffTask::query()
            ->whereHas('conversation', fn ($q) => $q->whereIn('channel_id', $business->channels()->select('id')))
            ->findOrFail($taskId);
        $this->ensureAgentOwnsTask($request, $business->id, $task);

        $note = $request->validate(['resolution_note' => ['sometimes', 'nullable', 'string', 'max:2000']])['resolution_note'] ?? $task->resolution_note;

        $task->update(['status' => 'resolved', 'resolved_at' => now(), 'resolution_note' => $note]);
        $task->conversation()->update([
            'requires_human' => false,
            'status' => 'open',
        ]);

        return response()->json(['task' => $task->fresh(), 'message' => 'Handoff task resolved.']);
    }

    public function assign(Request $request, int $taskId, ActiveBusiness $activeBusiness)
    {
        $business = $activeBusiness->resolve($request, permission: 'conversation_write');
        $validated = $request->validate(['assigned_to' => 'required|integer']);
        $isOwner = (int) $business->user_id === (int) $validated['assigned_to'];
        $member = $isOwner ? null : TeamMember::query()
            ->where('business_id', $business->id)->where('user_id', $validated['assigned_to'])
            ->where('is_active', true)->whereIn('role', ['owner', 'manager', 'agent'])->firstOrFail();
        $task = HandoffTask::query()
            ->whereHas('conversation', fn ($q) => $q->whereIn('channel_id', $business->channels()->select('id')))
            ->findOrFail($taskId);
        $this->ensureAgentOwnsTask($request, $business->id, $task);

        $assigneeId = $isOwner ? (int) $business->user_id : (int) $member->user_id;
        $task->update(['assigned_to' => $assigneeId]);
        $task->conversation()->update(['assigned_agent_id' => $assigneeId, 'assigned_at' => now()]);
        app(\App\Services\HandoffTaskService::class)->notifyAssignedTask($task->fresh());

        return response()->json(['task' => $task->fresh()->load('assignee:id,name')]);
    }

    public function update(Request $request, int $taskId, ActiveBusiness $activeBusiness)
    {
        $business = $activeBusiness->resolve($request, permission: 'conversation_write');
        $validated = $request->validate([
            'priority' => ['sometimes', 'in:low,normal,high,urgent'],
            'due_at' => ['sometimes', 'nullable', 'date'],
        ]);
        abort_if($validated === [], 422, 'Provide a priority or due time to update.');
        $task = HandoffTask::query()->whereHas('conversation', fn ($q) => $q->whereIn('channel_id', $business->channels()->select('id')))->findOrFail($taskId);
        $this->ensureAgentOwnsTask($request, $business->id, $task);
        $task->update($validated);
        return response()->json(['task' => $task->fresh()->load(['assignee:id,name', 'conversation:id,channel_id,sender_name,sender_id'])]);
    }

    public function assignees(Request $request, ActiveBusiness $activeBusiness)
    {
        $business = $activeBusiness->resolve($request, permission: 'conversation_read');
        $members = TeamMember::query()->where('business_id', $business->id)->where('is_active', true)
            ->whereIn('role', ['owner', 'manager', 'agent'])->with('user:id,name,email')->get()
            ->map(fn (TeamMember $member) => ['id' => $member->user_id, 'name' => $member->user?->name, 'email' => $member->user?->email, 'role' => $member->role]);
        $owner = \App\Models\User::query()->find($business->user_id);
        if ($owner && !$members->contains(fn ($member) => (int) $member['id'] === (int) $owner->id)) {
            $members->prepend(['id' => $owner->id, 'name' => $owner->name, 'email' => $owner->email, 'role' => 'owner']);
        }
        return response()->json($members->values());
    }

    private function ensureAgentOwnsTask(Request $request, int $businessId, HandoffTask $task): void
    {
        $membership = TeamMember::query()->where('business_id', $businessId)->where('user_id', $request->user()->id)->where('is_active', true)->first();
        if ($membership?->role === 'agent' && (int) $task->assigned_to !== (int) $request->user()->id) abort(403);
    }
}
