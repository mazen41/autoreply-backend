<?php

namespace App\Services;

use App\Models\Channel;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\ProductMessageMap;
use Illuminate\Support\Facades\Log;

class ReferencedProductResolver
{
    /** Resolve a product only from a map in this conversation and its active commerce connection. */
    public function resolve(Message $message, Conversation $conversation, ?Channel $commerceConnection): array
    {
        $content = (string) $message->content;
        $pointer = $this->hasPointer($content);
        $metadata = $message->metadata ?? [];
        $referenceId = $metadata['referenced_platform_message_id'] ?? $metadata['quoted_message_id'] ?? null;
        $channelType = $message->source === 'web_chat'
            ? 'web_chat'
            : (string) ($conversation->channel?->type ?? 'unknown');

        if ($channelType === 'telegram' && $referenceId !== null && !str_contains((string) $referenceId, ':')) {
            $referenceId = (string) $conversation->sender_id . ':' . (string) $referenceId;
        }

        $eligibleConnection = $commerceConnection
            && (int) $commerceConnection->business_id === (int) $conversation->business_id
            && in_array($commerceConnection->type, ['salla', 'shopify', 'woocommerce'], true)
            && $commerceConnection->status === 'connected'
            ? $commerceConnection
            : null;

        $base = [
            'resolution' => 'none',
            'match_method' => $referenceId ? 'quote' : ($channelType === 'gmail' ? 'thread' : 'none'),
            'referenced_platform_message_id' => $referenceId ? (string) $referenceId : null,
            'product' => null,
            'candidates' => [],
            'is_pointer' => $pointer,
        ];

        if (!$eligibleConnection) {
            return $base;
        }

        $query = ProductMessageMap::query()
            ->where('conversation_id', $conversation->id)
            ->where('channel_id', $conversation->channel_id)
            ->where('commerce_channel_id', $eligibleConnection->id)
            ->where('commerce_provider', $eligibleConnection->type);

        $pendingConfirmationMessage = Message::query()
            ->where('conversation_id', $conversation->id)
            ->where('direction', 'outbound')
            ->where('is_ai', true)
            ->where('id', '<', $message->id)
            ->whereNotNull('metadata')
            ->orderByDesc('id')
            ->first();
        $pendingConfirmation = data_get($pendingConfirmationMessage?->metadata, 'pending_product_confirmation');
        if (is_array($pendingConfirmation)
            && (int) ($pendingConfirmation['commerce_channel_id'] ?? 0) === (int) $eligibleConnection->id) {
            if ($this->matchesPhrase($content, config('referenced_products.negative_phrases', []))) {
                $this->clearPendingConfirmation($pendingConfirmationMessage);
            } elseif ($this->matchesPhrase($content, config('referenced_products.affirmative_phrases', []))) {
                $confirmedMap = (clone $query)
                    ->where('commerce_external_id', (string) ($pendingConfirmation['commerce_external_id'] ?? ''))
                    ->where('created_at', '>=', now()->subMinutes(10))
                    ->latest('id')
                    ->first();
                if ($confirmedMap && $this->mapIsResolvable($confirmedMap)) {
                    $this->clearPendingConfirmation($pendingConfirmationMessage);
                    $base['resolution'] = 'resolved';
                    $base['match_method'] = 'recent';
                    $base['product'] = $this->toProduct($confirmedMap);
                    $base['is_pointer'] = true;
                    $base['is_confirmation_followup'] = true;
                    return $base;
                }
            }
        }

        // Quoting our own pending order summary is a confirmation reference, not a product reference.
$checkoutForRef = is_array($conversation->checkout_state) ? $conversation->checkout_state : [];
$summaryRow = !empty($checkoutForRef['confirmation_summary_message_id'])
    ? \App\Models\Message::query()->find((int) $checkoutForRef['confirmation_summary_message_id'])
    : null;
$summaryReferences = array_filter([
    (string) ($checkoutForRef['confirmation_summary_message_id'] ?? ''),
    (string) ($summaryRow?->metadata['platform_message_id'] ?? ''),
]);
if ($referenceId !== null && in_array((string) $referenceId, $summaryReferences, true)) {
    $referenceId = null;
}
if ($referenceId !== null && $referenceId !== '') {
            $mapQuery = clone $query;
            if ($channelType === 'web_chat' && str_starts_with((string) $referenceId, 'internal:')) {
                $mapQuery->where('internal_message_id', (int) substr((string) $referenceId, 9));
            } else {
                $mapQuery->where('platform_message_id', (string) $referenceId);
            }
            $map = $mapQuery->latest('id')->first();
            if ($map) {
                if ($this->mapIsResolvable($map)) {
                    $base['resolution'] = 'resolved';
                    $base['product'] = $this->toProduct($map);
                    return $base;
                }

                Log::warning('Referenced product map missing required store or external product identity', [
                    'message_id' => $message->id,
                    'conversation_id' => $conversation->id,
                    'map_id' => $map->id,
                    'store_id' => $map->commerce_channel_id,
                ]);
                $base['resolution'] = 'ambiguous';
            }
        }

        // A quote that misses exact lookup can show recent scoped choices, but
        // must never auto-resolve to one of them.
        // Gmail replies are associated by Gmail thread. Resolve only a single
        // most-recent product map in that same thread/store; otherwise clarify.
        if ($channelType === 'gmail') {
            $threadMap = (clone $query)
                ->where('created_at', '>=', now()->subMinutes(10))
                ->whereNotNull('platform_message_id')
                ->orderByDesc('created_at')
                ->first();
            if ($threadMap && $this->mapIsResolvable($threadMap)) {
                $base['resolution'] = 'resolved';
                $base['match_method'] = 'thread';
                $base['product'] = $this->toProduct($threadMap);
                return $base;
            }
        }

        if (!$pointer && empty($referenceId)) {
            return $base;
        }

        $recent = (clone $query)
            ->where('created_at', '>=', now()->subMinutes(10))
            ->orderByDesc('created_at')
            ->limit(20)
            ->get()
            ->filter(fn (ProductMessageMap $map) => $this->mapIsResolvable($map))
            ->unique('commerce_external_id')
            ->values();

        $base['match_method'] = $referenceId ? 'quote' : 'recent';
        $base['candidates'] = $recent->map(fn (ProductMessageMap $map) => $this->toProduct($map))->all();

        if ($recent->count() === 1 && empty($referenceId)) {
            $base['resolution'] = 'resolved_recent';
            $base['product'] = $this->toProduct($recent->first());
        } elseif ($recent->count() > 1 || ($referenceId !== null && $recent->count() > 0)) {
            $base['resolution'] = 'ambiguous';
        }

        return $base;
    }

    public function intentFor(string $text, array $resolution): ?string
    {
        if (!empty($resolution['is_confirmation_followup'])) {
            return 'place_order';
        }

        $hasDirectReference = in_array($resolution['match_method'] ?? 'none', ['quote', 'thread'], true);
        if (empty($resolution['is_pointer']) && !$hasDirectReference) {
            return null;
        }
        if (!in_array($resolution['resolution'] ?? 'none', ['resolved', 'resolved_recent'], true)) {
            return null;
        }

        return $this->hasOrderingLanguage($text) ? 'place_order' : 'question';
    }

    public function hasPointer(string $text): bool
    {
        return $this->matchesPhrase($text, config('referenced_products.pointers', []));
    }

    public function hasOrderingLanguage(string $text): bool
    {
        return $this->matchesPhrase($text, config('referenced_products.ordering_phrases', []));
    }

    private function matchesPhrase(string $text, array $phrases): bool
    {
        foreach ($phrases as $phrase) {
            $quoted = preg_quote((string) $phrase, '/');
            if (preg_match('/(?<![\p{L}\p{N}])' . $quoted . '(?![\p{L}\p{N}])/iu', $text)) {
                return true;
            }
        }
        return false;
    }

    private function clearPendingConfirmation(?Message $message): void
    {
        if (!$message) {
            return;
        }
        $metadata = $message->metadata ?? [];
        unset($metadata['pending_product_confirmation']);
        $message->update(['metadata' => $metadata ?: null]);
    }

    private function mapIsResolvable(ProductMessageMap $map): bool
    {
        return !empty($map->commerce_channel_id) && !empty($map->commerce_external_id);
    }

    private function toProduct(ProductMessageMap $map): array
    {
        return [
            'id' => (string) $map->commerce_external_id,
            'salla_product_id' => $map->salla_product_id ?: (string) $map->commerce_external_id,
            'internal_product_id' => $map->internal_product_id,
            'commerce_external_id' => (string) $map->commerce_external_id,
            'commerce_channel_id' => (int) $map->commerce_channel_id,
            'provider' => $map->commerce_provider,
            'message_provider' => $map->provider,
            'sku' => $map->sku,
            'name' => $map->product_name ?: 'Product',
            'price' => $map->product_price,
            'currency' => $map->currency ?: 'SAR',
            'image' => $map->image_url,
        ];
    }
}
