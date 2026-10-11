<?php

namespace App\Services;

use App\Models\ContactConsent;
use App\Models\Message;
use App\Models\Suppression;
use Illuminate\Support\Facades\Log;

class InboundConsentKeywordHandler
{
    public function handle(Message $message): void
    {
        if ($message->direction !== 'inbound' || !is_string($message->content)) {
            return;
        }

        $keyword = $this->normalizeKeyword($message->content);
        $stopKeywords = array_map(fn ($value) => $this->normalizeKeyword($value), config('consent.opt_out_keywords', []));
        $startKeywords = array_map(fn ($value) => $this->normalizeKeyword($value), config('consent.opt_in_keywords', []));
        $action = in_array($keyword, $stopKeywords, true) ? 'opt_out'
            : (in_array($keyword, $startKeywords, true) ? 'opt_in' : null);
        if (!$action) {
            return;
        }

        try {
            $conversation = $message->conversation()->with('channel')->first();
            $channel = $conversation?->channel;
            $businessId = $conversation?->business_id ?: $channel?->business_id;
            $contact = trim((string) ($conversation?->sender_id ?? ''));
            $channelType = strtolower((string) ($channel?->type ?? ''));
            if (!$businessId || $contact === '' || $channelType === '') {
                return;
            }

            $identifier = app(ConsentGate::class)->normalizeIdentifier($contact, $channelType);
            ContactConsent::updateOrCreate(
                [
                    'business_id' => $businessId,
                    'channel' => $channelType,
                    'contact_identifier' => $identifier,
                    'scope' => 'marketing',
                ],
                [
                    'status' => $action === 'opt_out' ? 'opted_out' : 'opted_in',
                    'source' => 'inbound_keyword',
                    'captured_at' => now(),
                    'evidence' => $keyword,
                ]
            );

            if ($action === 'opt_out') {
                Suppression::updateOrCreate(
                    [
                        'business_id' => $businessId,
                        'channel' => $channelType,
                        'contact_identifier' => $identifier,
                        'reason' => 'unsubscribed',
                    ],
                    ['source' => 'inbound_keyword', 'suppressed_at' => now()]
                );
            } else {
                Suppression::where('business_id', $businessId)
                    ->where('channel', $channelType)
                    ->where('contact_identifier', $identifier)
                    ->where('reason', 'unsubscribed')
                    ->delete();
            }
        } catch (\Throwable $exception) {
            Log::warning('Inbound consent keyword processing failed', [
                'message_id' => $message->id,
                'conversation_id' => $message->conversation_id,
                'exception_type' => get_class($exception),
            ]);
        }
    }

    private function normalizeKeyword(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $value = preg_replace('/[[:punct:]،؛؟]+/u', ' ', $value) ?? $value;
        $value = preg_replace('/[\x{064B}-\x{065F}\x{0670}]/u', '', $value) ?? $value;
        $value = strtr($value, ['أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ى' => 'ي']);
        return trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
    }
}
