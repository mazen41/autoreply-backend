<?php

namespace App\Services;

use App\Models\AuditLog;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;

class AuditLoggerService
{
    /**
     * Log an audit event.
     *
     * @param string $action The action being performed (e.g., 'bot.created', 'conversation.escalated')
     * @param Model|null $auditable The model being acted upon (optional)
     * @param array $payload Additional context data (optional)
     * @param int|null $userId Override the authenticated user ID (optional)
     * @param int|null $businessProfileId Override the business profile ID (optional)
     * @return AuditLog
     */
    public static function log(
        string $action,
        ?Model $auditable = null,
        array $payload = [],
        ?int $userId = null,
        ?int $businessProfileId = null
    ): AuditLog {
        $user = Auth::user();

        return AuditLog::create([
            'user_id' => $userId ?? $user?->id,
            'business_profile_id' => $businessProfileId ?? $user?->business_id,
            'action' => $action,
            'auditable_type' => $auditable ? get_class($auditable) : null,
            'auditable_id' => $auditable?->id,
            'payload' => !empty($payload) ? $payload : null,
            'ip_address' => Request::ip(),
        ]);
    }

    /**
     * Log a bot-related action.
     */
    public static function logBotAction(string $action, ?Model $bot = null, array $payload = []): AuditLog
    {
        return self::log("bot.{$action}", $bot, $payload);
    }

    /**
     * Log a conversation-related action.
     */
    public static function logConversationAction(string $action, ?Model $conversation = null, array $payload = []): AuditLog
    {
        return self::log("conversation.{$action}", $conversation, $payload);
    }

    /**
     * Log a security-related action.
     */
    public static function logSecurityAction(string $action, array $payload = []): AuditLog
    {
        return self::log("security.{$action}", null, $payload);
    }

    /**
     * Log an admin-related action.
     */
    public static function logAdminAction(string $action, ?Model $auditable = null, array $payload = []): AuditLog
    {
        return self::log("admin.{$action}", $auditable, $payload);
    }
}
