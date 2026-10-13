<?php

namespace App\Services;

use App\Jobs\ProcessSocialCommentEvent;
use App\Models\Channel;
use App\Models\SocialCommentEvent;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\Client\ConnectionException;

class MetaCommentAutomationService
{
    /** Persist before queuing; a duplicate Meta delivery safely requeues an unprocessed event. */
    public function receive(string $platform, string $entryAccountId, array $normalized, array $payload): ?SocialCommentEvent
    {
        $accountId = trim((string) ($normalized['account_id'] ?: $entryAccountId));
        $commentId = trim((string) ($normalized['comment_id'] ?? ''));
        if ($accountId === '' || $commentId === '') return null;

        $query = Channel::query()->where('type', $platform)->where('status', 'connected');
        $query->where($platform === 'facebook' ? 'page_id' : 'instagram_account_id', $accountId);
        $channels = $query->get();
        if ($channels->count() !== 1) {
            Log::warning('Meta comment event account resolution was ambiguous or missing', [
                'platform' => $platform,
                'account_id' => $accountId,
                'candidate_count' => $channels->count(),
            ]);
            return null;
        }

        /** @var Channel $channel */
        $channel = $channels->first();
        if (!$channel->business_id) return null;

        try {
            $event = SocialCommentEvent::firstOrCreate(
                [
                    'platform' => $platform,
                    'channel_id' => $channel->id,
                    'comment_id' => $commentId,
                    'event_type' => $normalized['event_type'] ?? 'comment_created',
                ],
                [
                    'business_id' => $channel->business_id,
                    'account_id' => $accountId,
                    'post_id' => $normalized['post_id'] ?? null,
                    'parent_comment_id' => $normalized['parent_comment_id'] ?? null,
                    'commenter_id' => $normalized['commenter_id'] ?? null,
                    'comment_text' => $normalized['comment_text'] ?? null,
                    'platform_created_at' => $this->parseTimestamp($normalized['platform_created_at'] ?? null),
                    'is_live_comment' => (bool) ($normalized['is_live_comment'] ?? false),
                    // Keep normalized event fields and avoid retaining unrelated webhook data.
                    'payload' => ['source' => $platform],
                    'status' => 'received',
                ]
            );
        } catch (QueryException $exception) {
            // A concurrent retry may win the unique insert. Re-read its durable row.
            $event = SocialCommentEvent::query()
                ->where('platform', $platform)->where('channel_id', $channel->id)
                ->where('comment_id', $commentId)->where('event_type', $normalized['event_type'] ?? 'comment_created')
                ->first();
            if (!$event) throw $exception;
        }

        if (in_array($event->status, ['received', 'retryable'], true)) {
            ProcessSocialCommentEvent::dispatch($event->id)->afterCommit();
        }
        return $event;
    }

    public function publishPublic(SocialCommentEvent $event, Channel $channel, string $text): array
    {
        if ($event->public_reply_id) return ['success' => true, 'reply_id' => $event->public_reply_id];
        return $this->publish($event, $channel, $text, false);
    }

    /** Meta private replies are comment-scoped and intentionally never fall back to /me/messages. */
    public function publishPrivate(SocialCommentEvent $event, Channel $channel, string $text): array
    {
        if ($event->private_reply_id) return ['success' => true, 'reply_id' => $event->private_reply_id];
        if ($event->event_type !== 'comment_created' || !$event->platform_created_at
            || $event->platform_created_at->lt(now()->subDays(7))
            || ($event->platform === 'instagram' && $event->is_live_comment)) {
            return ['success' => false, 'skipped' => true, 'reason' => 'private_reply_ineligible'];
        }
        return $this->publish($event, $channel, $text, true);
    }

    private function publish(SocialCommentEvent $event, Channel $channel, string $text, bool $private): array
    {
        $graphVersion = config('services.meta.graph_version', 'v26.0');
        $edge = $private
            ? "{$event->account_id}/messages"
            : ($event->platform === 'instagram'
                ? "{$event->comment_id}/replies"
                : "{$event->comment_id}/comments");
        $payload = $private
            ? ['recipient' => ['comment_id' => $event->comment_id], 'message' => ['text' => $text]]
            : ['message' => $text];

        try {
            $response = Http::timeout(15)->post("https://graph.facebook.com/{$graphVersion}/{$edge}", $payload + [
                'access_token' => $channel->access_token,
            ]);
        } catch (ConnectionException) {
            return ['success' => false, 'retryable' => true, 'error_code' => 'transport_error'];
        }
        $responseId = $response->json('id') ?? $response->json('message_id');
        if (!$response->successful() || !$responseId) {
            $errorCode = (string) ($response->json('error.code') ?? $response->status());
            Log::warning('Meta comment reply rejected', [
                'event_id' => $event->id,
                'channel_id' => $channel->id,
                'platform' => $event->platform,
                'private' => $private,
                'http_status' => $response->status(),
                'error_code' => $errorCode,
            ]);
            return [
                'success' => false,
                'retryable' => $response->status() === 429 || $response->serverError(),
                'error_code' => $errorCode,
            ];
        }

        $column = $private ? 'private_reply_id' : 'public_reply_id';
        $event->forceFill([$column => (string) $responseId])->save();
        return ['success' => true, 'reply_id' => (string) $responseId];
    }

    public function publicReplyAllowed(string $text, array $settings, array $restrictedProductNames = [], array $restrictedPrices = []): bool
    {
        $text = trim($text);
        if ($text === '' || mb_strlen($text) > 500) return false;
        // Never expose order/customer-specific data in a public comment.
        if (preg_match('/(?:\border\s*#?\s*\d{3,}|\+?\d[\d\s().-]{7,}\d|\b[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}\b|\b(?:address|phone|email|order details)\b|رقم الطلب|العنوان|رقم الهاتف)/iu', $text)) return false;

        $allowed = $settings['public_information'] ?? [];
        if (!in_array('product_prices', $allowed, true)
            && preg_match('/(?:[$€£]|\b(?:SAR|USD|EGP|AED|د\.إ|ر\.س)\b)\s*\d|\d\s*(?:[$€£]|\b(?:SAR|USD|EGP|AED|ر\.س)\b)/iu', $text)) return false;
        if (!in_array('availability', $allowed, true)
            && preg_match('/\b(?:in stock|out of stock|available|unavailable|متوفر|غير متوفر)\b/iu', $text)) return false;
        if (!in_array('discounts', $allowed, true)
            && preg_match('/\b(?:discount|coupon|promo(?:tion)?|offer|خصم|كوبون|عرض)\b/iu', $text)) return false;
        if (!in_array('product_links', $allowed, true) && preg_match('~https?://|www\.~iu', $text)) return false;
        if (!in_array('product_names', $allowed, true)) {
            foreach ($restrictedProductNames as $name) {
                $name = trim((string) $name);
                if ($name !== '' && mb_stripos($text, $name) !== false) return false;
            }
        }
        if (!in_array('product_prices', $allowed, true)) {
            foreach ($restrictedPrices as $price) {
                $price = trim((string) $price);
                if ($price !== '' && preg_match('/(?<!\d)' . preg_quote($price, '/') . '(?!\d)/u', $text)) return false;
            }
        }
        return true;
    }

    private function parseTimestamp(mixed $value): mixed
    {
        if ($value === null || $value === '') return null;
        try {
            return is_numeric($value) ? now()->setTimestamp((int) $value) : $value;
        } catch (\Throwable) {
            return null;
        }
    }
}
