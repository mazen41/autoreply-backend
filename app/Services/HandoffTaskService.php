<?php

namespace App\Services;

use App\Models\Conversation;
use App\Models\HandoffTask;
use App\Models\Notification;
use App\Models\TeamMember;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

class HandoffTaskService
{
    public function openForConversation(Conversation $conversation, string $reason, string $priority = 'normal'): HandoffTask
    {
        $business = $conversation->business;
        $assignedTo = $conversation->assigned_agent_id;
        if ($assignedTo && (int) $assignedTo !== (int) $business?->user_id
            && !TeamMember::query()->where('business_id', $business?->id)
                ->where('user_id', $assignedTo)->where('is_active', true)->exists()) {
            $assignedTo = null;
        }

        $task = HandoffTask::firstOrCreate(
            ['conversation_id' => $conversation->id],
            [
                'reason' => $reason,
                'priority' => $priority,
                'status' => 'open',
                'assigned_to' => $assignedTo,
                'due_at' => now()->addMinutes((int) config('handoff.sla_minutes', 30)),
            ]
        );

        $reopened = false;
        if (!$task->wasRecentlyCreated && $task->status !== 'open') {
            $task->fill([
                'reason' => $reason,
                'priority' => $priority,
                'status' => 'open',
                'assigned_to' => $assignedTo,
                'resolved_at' => null,
                'due_at' => now()->addMinutes((int) config('handoff.sla_minutes', 30)),
            ])->save();
            $reopened = true;
        }

        if ($task->wasRecentlyCreated || $reopened) {
            $this->notify($task->fresh(), $conversation);
            try {
                $openedTask = $task->fresh();
                app(AutomationEngine::class)->executeWorkflowsForEvent(
                    AutomationEngine::EVENT_ESCALATION_TRIGGERED,
                    $conversation,
                    [
                        'handoff_task' => $openedTask,
                        'handoff_task_id' => $openedTask->id,
                        'opened_at' => $openedTask->updated_at?->utc()->toIso8601String(),
                        'event_id' => 'handoff:' . $openedTask->id . ':' . $openedTask->updated_at?->utc()->toIso8601String(),
                    ]
                );
            } catch (\Throwable $exception) {
                report($exception);
            }
        }

        return $task->fresh();
    }

    public function notifyAssignedTask(HandoffTask $task): void
    {
        $this->notify($task, $task->conversation()->with('business')->firstOrFail());
    }

    private function notify(HandoffTask $task, Conversation $conversation): void
    {
        $business = $conversation->business;
        if (!$business) {
            return;
        }

        $recipientIds = collect([$task->assigned_to, $business->user_id])
            ->filter()
            ->merge(TeamMember::query()
                ->where('business_id', $business->id)
                ->where('is_active', true)
                ->whereIn('role', ['owner', 'manager', 'agent'])
                ->pluck('user_id'))
            ->unique()
            ->values();

        $users = User::query()->whereIn('id', $recipientIds)->get(['id', 'email']);
        foreach ($users as $user) {
            $notification = Notification::firstOrCreate(
                [
                    'user_id' => $user->id,
                    'type' => 'handoff_task',
                    'data->handoff_task_id' => $task->id,
                ],
                [
                    'title' => 'Customer needs a team member',
                    'message' => 'A customer conversation needs human attention.',
                    'data' => ['handoff_task_id' => $task->id, 'conversation_id' => $conversation->id],
                    'action_url' => '/dashboard/inbox?conversation=' . $conversation->id,
                    'is_read' => false,
                ]
            );
            if ($notification->wasRecentlyCreated) {
                broadcast(new \App\Events\NotificationCreated($notification));
            }

            if (config('handoff.email_notifications', true) && $user->email) {
                try {
                    Mail::raw(
                        "A customer conversation needs human attention.\n\nConversation: #{$conversation->id}\nReason: {$task->reason}\nDue: {$task->due_at?->toDateTimeString()}\n\nOpen the inbox: " . url('/dashboard/inbox?conversation=' . $conversation->id),
                        fn ($message) => $message->to($user->email)->subject('Customer handoff needs attention')
                    );
                } catch (\Throwable $exception) {
                    report($exception);
                }
            }
        }

        $configuredEmail = trim((string) $business->handoff_notification_email);
        if (filter_var($configuredEmail, FILTER_VALIDATE_EMAIL)
            && !$users->contains(fn (User $user) => mb_strtolower((string) $user->email) === mb_strtolower($configuredEmail))
            && config('handoff.email_notifications', true)) {
            try {
                Mail::raw(
                    "A customer conversation needs a team member.\n\nConversation: #{$conversation->id}\nReason: {$task->reason}\nDue: {$task->due_at?->toDateTimeString()}\n\nOpen the inbox: " . url('/dashboard/inbox?conversation=' . $conversation->id),
                    fn ($message) => $message->to($configuredEmail)->subject('Customer handoff needs attention')
                );
            } catch (\Throwable $exception) {
                report($exception);
            }
        }
    }
}
