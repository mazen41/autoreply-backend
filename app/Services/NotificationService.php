<?php

namespace App\Services;

use App\Models\Notification;
use Illuminate\Support\Facades\Log;

class NotificationService
{
    /**
     * Create a notification for a user
     */
    public function createNotification(
        int $userId,
        string $type,
        string $title,
        string $message,
        array $data = null,
        string $actionUrl = null
    ): Notification {
        $notification = Notification::create([
            'user_id' => $userId,
            'type' => $type,
            'title' => $title,
            'message' => $message,
            'data' => $data,
            'action_url' => $actionUrl,
            'is_read' => false,
        ]);

        // Broadcast via WebSocket (Pusher/Laravel Echo)
        broadcast(new \App\Events\NotificationCreated($notification));

        Log::info('Notification created', [
            'user_id' => $userId,
            'type' => $type,
            'title' => $title,
        ]);

        return $notification;
    }

    /**
     * Create new message notification
     */
    public function newMessage(int $userId, string $senderName, int $conversationId): void
    {
        $this->createNotification(
            $userId,
            'new_message',
            'New Message',
            "You have a new message from {$senderName}",
            ['conversation_id' => $conversationId],
            "/dashboard/inbox?conversation={$conversationId}"
        );
    }

    /**
     * Create new assignment notification
     */
    public function newAssignment(int $userId, string $conversationId): void
    {
        $this->createNotification(
            $userId,
            'new_assignment',
            'New Assignment',
            'You have been assigned to a new conversation',
            ['conversation_id' => $conversationId],
            "/dashboard/inbox?conversation={$conversationId}"
        );
    }

    /**
     * Create failed message notification
     */
    public function failedMessage(int $userId, string $errorMessage): void
    {
        $this->createNotification(
            $userId,
            'failed_message',
            'Message Failed',
            "A message failed to send: {$errorMessage}",
            ['error' => $errorMessage],
            '/dashboard/inbox'
        );
    }

    /**
     * Create overage alert notification
     */
    public function overageAlert(int $userId, int $extraMessages, float $amount): void
    {
        $this->createNotification(
            $userId,
            'overage_alert',
            'Usage Limit Exceeded',
            "You've exceeded your limit by {$extraMessages} messages. Additional charges apply.",
            ['extra_messages' => $extraMessages, 'amount' => $amount],
            '/dashboard/billing'
        );
    }

    /**
     * Create payment failed notification
     */
    public function paymentFailed(int $userId): void
    {
        $this->createNotification(
            $userId,
            'payment_failed',
            'Payment Failed',
            'Your payment has failed. Please update your payment method.',
            null,
            '/dashboard/billing'
        );
    }

    /**
     * Create negative CSAT notification
     */
    public function negativeCsat(int $userId, string $feedback, int $conversationId): void
    {
        $this->createNotification(
            $userId,
            'csat_negative',
            'Negative Feedback Received',
            "A customer left negative feedback: {$feedback}",
            ['conversation_id' => $conversationId, 'feedback' => $feedback],
            "/dashboard/inbox?conversation={$conversationId}"
        );
    }

    /**
     * Create escalation notification
     */
    public function escalation(int $userId, string $reason, int $conversationId): void
    {
        $this->createNotification(
            $userId,
            'escalation',
            'Conversation Escalated',
            "A conversation has been escalated: {$reason}",
            ['conversation_id' => $conversationId, 'reason' => $reason],
            "/dashboard/inbox?conversation={$conversationId}"
        );
    }

    /**
     * Create campaign sent notification
     */
    public function campaignSent(int $userId, string $campaignName, int $sentCount): void
    {
        $this->createNotification(
            $userId,
            'campaign_sent',
            'Campaign Sent',
            "Campaign '{$campaignName}' has been sent to {$sentCount} recipients.",
            ['campaign_name' => $campaignName, 'sent_count' => $sentCount],
            '/dashboard/campaigns'
        );
    }

    /**
     * Create sequence completed notification
     */
    public function sequenceCompleted(int $userId, string $sequenceName, int $conversationId): void
    {
        $this->createNotification(
            $userId,
            'sequence_completed',
            'Sequence Completed',
            "Sequence '{$sequenceName}' has been completed for a conversation.",
            ['sequence_name' => $sequenceName, 'conversation_id' => $conversationId],
            "/dashboard/inbox?conversation={$conversationId}"
        );
    }

    /**
     * Mark notification as read
     */
    public function markAsRead(int $notificationId, int $userId): bool
    {
        $notification = Notification::where('id', $notificationId)
            ->where('user_id', $userId)
            ->first();

        if (!$notification) {
            return false;
        }

        $notification->markAsRead();
        return true;
    }

    /**
     * Mark all notifications as read for a user
     */
    public function markAllAsRead(int $userId): int
    {
        return Notification::where('user_id', $userId)
            ->unread()
            ->update([
                'is_read' => true,
                'read_at' => now(),
            ]);
    }

    /**
     * Get unread count for a user
     */
    public function getUnreadCount(int $userId): int
    {
        return Notification::where('user_id', $userId)
            ->unread()
            ->count();
    }
}
