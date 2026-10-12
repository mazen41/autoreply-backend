<?php

namespace App\Services;

use App\Models\Message;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * Writes one detailed log line per message creation and per delivery status change.
 * Covers merchant replies, AI replies, campaigns, sequences, and customer messages.
 */
class MessageAuditLogger
{
    public function created(Message $message): void
    {
        if (! config('message_audit.enabled', true)) {
            return;
        }

        $context = $this->context($message);
        $context['preview'] = $this->preview($message->content);

        Log::info('Message audit: created', $context);
    }

    public function statusChanged(Message $message): void
    {
        if (! config('message_audit.enabled', true)) {
            return;
        }

        $changes = [];
        if ($message->wasChanged('content')) {
            $changes['content'] = [
                'from_hash' => self::hash($message->getOriginal('content')),
                'to_hash' => self::hash($message->content),
                'to_length' => mb_strlen((string) $message->content),
            ];
        }

        foreach (['send_status', 'delivery_status', 'error_details'] as $field) {
            if ($message->wasChanged($field)) {
                $changes[$field] = [
                    'from' => $message->getOriginal($field),
                    'to' => $field === 'error_details' ? $this->preview($message->$field, 300) : $message->$field,
                ];
            }
        }

        if ($changes === []) {
            return;
        }

        $context = $this->context($message);
        $context['changes'] = $changes;

        Log::info('Message audit: status changed', $context);
    }

    public function preview(?string $text, ?int $max = null): ?string
    {
        $max ??= (int) config('message_audit.preview_chars', 300);
        if ($text === null || $text === '' || $max <= 0) {
            return null;
        }

        $clean = preg_replace('/[\w.+-]+@[\w-]+\.[\w.-]+/u', '[email]', $text) ?? $text;
        $clean = preg_replace('/\+?\d[\d\s\-]{6,}\d/u', '[phone]', $clean) ?? $clean;

        return mb_strlen($clean) > $max
            ? mb_substr($clean, 0, $max) . '…'
            : $clean;
    }

    private function context(Message $message): array
    {
        $conversation = $message->conversation;

        return [
            'message_id' => $message->id,
            'conversation_id' => $message->conversation_id,
            'channel_id' => $conversation?->channel_id,
            'channel_type' => $conversation?->channel?->type,
            'direction' => $message->direction,
            'actor' => $this->actor($message),
            'source' => $message->source,
            'is_ai' => (bool) $message->is_ai,
            'type' => $message->type,
            'send_status' => $message->send_status,
            'delivery_status' => $message->delivery_status,
            'intent' => $message->intent,
            'confidence_score' => $message->confidence_score,
            'content_length' => mb_strlen((string) $message->content),
        ];
    }

    private function actor(Message $message): string
    {
        if ($message->direction === 'inbound') {
            return 'customer';
        }
        if ($message->is_ai) {
            return 'ai';
        }
        $userId = Auth::id();

        return $userId ? 'merchant:' . $userId : ($message->source ?: 'system');
    }

    private static function hash(?string $text): ?string
    {
        return $text === null ? null : substr(sha1($text), 0, 12);
    }
}