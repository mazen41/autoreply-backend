<?php

namespace App\Services;

use App\Models\Bot;
use App\Models\Channel;
use Illuminate\Support\Facades\Log;

/**
 * Deterministic ecommerce channel routing (Salla / Shopify / WooCommerce).
 *
 * Multi-store businesses must never have an order looked up on, or created
 * in, a store chosen by accident. Resolution order:
 *
 *   1. Bot's explicit ecommerce_channel_id — when the conversation resolved
 *      to a bot that declares its store, that store IS the answer (if it is
 *      still connected and owned by the same user).
 *   2. The conversation's own channel — when the conversation itself lives
 *      on a channel of the requested type (e.g. a Salla-webhook-born
 *      conversation), that account is the deterministic owner.
 *   3. Unique connected channel of the type for the user — preserved
 *      single-store fallback. Returns null when 0 or 2+ exist: ambiguity
 *      is never resolved by guessing (no "latest connected" behavior).
 */
class EcommerceChannelResolver
{
    public function resolve(?Bot $bot, Channel $conversationChannel, string $type): ?Channel
    {
        // 1. Bot's explicit store
        if ($bot && !empty($bot->ecommerce_channel_id)) {
            $assigned = Channel::where('id', $bot->ecommerce_channel_id)
                ->where('user_id', $conversationChannel->user_id)
                ->where('type', $type)
                ->where('status', 'connected')
                ->first();

            if ($assigned) {
                return $assigned;
            }

            Log::warning('EcommerceChannelResolver: bot ecommerce_channel_id is not a usable connected channel of the requested type — falling through', [
                'bot_id'                 => $bot->id,
                'ecommerce_channel_id'   => $bot->ecommerce_channel_id,
                'requested_type'         => $type,
                'conversation_channel_id' => $conversationChannel->id,
            ]);
        }

        // 2. Conversation's own channel
        if ($conversationChannel->type === $type && $conversationChannel->status === 'connected') {
            return $conversationChannel;
        }

        // 3. Unique connected fallback (null when ambiguous)
        return $this->resolveUniqueConnectedChannel($conversationChannel->user_id, $type);
    }

    /**
     * Mirrors ProcessAutoReply::resolveUniqueConnectedChannel semantics:
     * a channel is returned only when EXACTLY ONE connected channel of the
     * type exists for the user. Never guesses among multiple accounts.
     */
    private function resolveUniqueConnectedChannel(int $userId, string $type): ?Channel
    {
        $candidates = Channel::where('user_id', $userId)
            ->where('type', $type)
            ->where('status', 'connected')
            ->get();

        if ($candidates->count() === 1) {
            return $candidates->first();
        }

        if ($candidates->count() > 1) {
            Log::warning("EcommerceChannelResolver: multiple {$type} channels for user {$userId} — skipping ambiguous lookup");
        }

        return null;
    }
}
