<?php

namespace App\Services;

use App\Models\Bot;
use App\Models\Channel;
use App\Models\Conversation;
use App\Models\EcommerceConnection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Resolves one authorized commerce account for a conversation.
 *
 * This is deliberately independent of provider choice and never accepts an
 * account identifier from an AI response.
 */
class EcommerceChannelResolver
{
    private const PROVIDERS = ['salla', 'shopify', 'woocommerce'];

    /** Resolve the commerce context for a bot/account pair without mutating a conversation. */
    public function resolveForChannel(Bot $bot, Channel $channel, int $businessId): array
    {
        if ((int) $bot->business_profile_id !== $businessId || (int) $channel->business_id !== $businessId
            || $bot->status !== 'active' || !$bot->channels()->where('channels.id', $channel->id)->exists()) {
            return ['status' => 'unresolved', 'source' => 'unresolved', 'connection' => null];
        }

        $eligibleIds = $this->eligibleConnectionIds($bot, $businessId);
        $route = DB::table('bot_channels')->where('bot_id', $bot->id)
            ->where('channel_id', $channel->id)->value('ecommerce_connection_id');
        if ($route) {
            $connection = in_array((int) $route, $eligibleIds, true) ? $this->connectedAccount((int) $route, $businessId) : null;
            return ['status' => $connection ? 'resolved' : 'unresolved', 'source' => $connection ? 'channel' : 'unresolved', 'connection' => $connection];
        }

        $defaultId = DB::table('bot_ecommerce_connections')->where('bot_id', $bot->id)
            ->where('is_enabled', true)->where('is_default', true)->value('ecommerce_connection_id');
        if ($defaultId && in_array((int) $defaultId, $eligibleIds, true)) {
            $connection = $this->connectedAccount((int) $defaultId, $businessId);
            if ($connection) return ['status' => 'resolved', 'source' => 'bot_default', 'connection' => $connection];
        }

        if (count($eligibleIds) === 1) {
            $connection = $this->connectedAccount($eligibleIds[0], $businessId);
            if ($connection) return ['status' => 'resolved', 'source' => 'single_available', 'connection' => $connection];
        }

        return ['status' => 'unresolved', 'source' => 'unresolved', 'connection' => null];
    }

    public function resolveConversation(Conversation $conversation, ?Bot $bot = null): array
    {
        $bot ??= $conversation->bot;
        $businessId = (int) $conversation->business_id;
        $eligibleIds = $this->eligibleConnectionIds($bot, $businessId);
        $channel = $conversation->channel;

        $chosen = null;
        $source = 'unresolved';

        // A persisted context is sticky. If it is no longer authorized, do not
        // silently switch the customer to a different store.
        if ($conversation->ecommerce_connection_id) {
            $stored = $this->connectedAccount((int) $conversation->ecommerce_connection_id, $businessId);
            if ($stored && (!$bot || in_array($stored->id, $eligibleIds, true))) {
                $chosen = $stored;
                $source = 'conversation';
            } else {
                Log::warning('Commerce context invalidated; refusing implicit store switch', [
                    'business_id' => $businessId,
                    'bot_id' => $bot?->id,
                    'conversation_id' => $conversation->id,
                    'connection_id' => $conversation->ecommerce_connection_id,
                ]);
            }
        } else {
            // Commerce webhooks can create conversations directly on a
            // provider channel. That account is deterministic by origin.
            if (
                $channel
                && in_array(strtolower((string) $channel->type), self::PROVIDERS, true)
                && $channel->status === 'connected'
                && (int) $channel->business_id === $businessId
                && (!$bot || in_array((int) $channel->id, $eligibleIds, true))
            ) {
                $chosen = $channel;
                $source = 'channel';
            }

            // Routing belongs to the Bot/channel assignment so two Bots sharing
            // one social account can still use different commerce stores.
            $botChannelRoute = $bot && $channel
                ? DB::table('bot_channels')
                    ->where('bot_id', $bot->id)
                    ->where('channel_id', $channel->id)
                    ->value('ecommerce_connection_id')
                : null;
            if (!$chosen && $botChannelRoute) {
                $candidate = $this->connectedAccount((int) $botChannelRoute, $businessId);
                if ($candidate && in_array($candidate->id, $eligibleIds, true)) {
                    $chosen = $candidate;
                    $source = 'channel';
                }
            }

            // Retain global channel routing only for legacy conversations with
            // no resolved Bot; it must never override a Bot-specific route.
            if (!$chosen && !$bot && $channel?->default_ecommerce_connection_id) {
                $candidate = $this->connectedAccount((int) $channel->default_ecommerce_connection_id, $businessId);
                if ($candidate) {
                    $chosen = $candidate;
                    $source = 'channel';
                }
            }

            if (!$chosen && $bot) {
                $defaultId = DB::table('bot_ecommerce_connections')
                    ->where('bot_id', $bot->id)
                    ->where('is_enabled', true)
                    ->where('is_default', true)
                    ->value('ecommerce_connection_id');
                if ($defaultId && in_array((int) $defaultId, $eligibleIds, true)) {
                    $chosen = $this->connectedAccount((int) $defaultId, $businessId);
                    if ($chosen) {
                        $source = 'bot_default';
                    }
                }
            }

            if (!$chosen && count($eligibleIds) === 1) {
                $chosen = $this->connectedAccount($eligibleIds[0], $businessId);
                if ($chosen) {
                    $source = 'single_available';
                }
            }
        }

        $status = $chosen ? 'resolved' : 'unresolved';
        $connectionId = $chosen?->id;
        if (
            $conversation->ecommerce_connection_id !== $connectionId
            || $conversation->commerce_context_status !== $status
            || $conversation->commerce_context_source !== $source
        ) {
            $conversation->forceFill([
                'ecommerce_connection_id' => $connectionId,
                'commerce_context_status' => $status,
                'commerce_context_source' => $source,
                'commerce_context_resolved_at' => $chosen ? now() : null,
            ])->save();
        }

        Log::info('Commerce context resolved', [
            'business_id' => $businessId,
            'bot_id' => $bot?->id,
            'conversation_id' => $conversation->id,
            'channel_id' => $channel?->id,
            'connection_id' => $connectionId,
            'provider' => $chosen?->type,
            'resolution_source' => $source,
            'status' => $status,
        ]);

        return [
            'status' => $status,
            'source' => $source,
            'connection' => $chosen,
        ];
    }

    /**
     * Legacy entry point retained for old integrations. It now enforces
     * business scoping and is intentionally unable to search other stores.
     */
    public function resolve(?Bot $bot, Channel $conversationChannel, string $type): ?Channel
    {
        if (!in_array($type, self::PROVIDERS, true)) {
            return null;
        }

        $businessId = (int) ($conversationChannel->business_id ?? $bot?->business_profile_id);
        if ($bot) {
            foreach ($this->eligibleConnectionIds($bot, $businessId) as $connectionId) {
                $eligible = $this->connectedAccount($connectionId, $businessId);
                if ($eligible && $eligible->type === $type) {
                    return $eligible;
                }
            }
            return null;
        }

        if ($conversationChannel->type === $type && $conversationChannel->status === 'connected') {
            return $this->connectedAccount((int) $conversationChannel->id, $businessId);
        }

        return null;
    }

    private function eligibleConnectionIds(?Bot $bot, int $businessId): array
    {
        if ($bot && ((int) $bot->business_profile_id !== $businessId || $bot->status !== 'active')) {
            return [];
        }

        if (!$bot) {
            return EcommerceConnection::query()
                ->where('business_id', $businessId)
                ->where('status', 'connected')
                ->orderBy('id')
                ->limit(2)
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();
        }

        $mapped = DB::table('bot_ecommerce_connections as bec')
            ->join('channels as c', 'c.id', '=', 'bec.ecommerce_connection_id')
            ->where('bec.bot_id', $bot->id)
            ->where('bec.is_enabled', true)
            ->where('c.business_id', $businessId)
            ->where('c.status', 'connected')
            ->whereIn('c.type', self::PROVIDERS)
            ->pluck('c.id')
            ->map(fn ($id) => (int) $id)
            ->all();

        if ($mapped) {
            return $mapped;
        }

        if ($bot->ecommerceConnections()->exists()) {
            // An explicit but disabled Bot mapping must not be revived by the
            // legacy scalar column or the zero-configuration fallback.
            return [];
        }

        // Legacy Bot store assignment is still honored during mixed-version
        // deployments; the migration materializes this assignment in the pivot.
        if ($bot->ecommerce_channel_id) {
            $legacy = $this->connectedAccount((int) $bot->ecommerce_channel_id, $businessId);
            return $legacy ? [(int) $legacy->id] : [];
        }

        // A single connected account in the Bot's business remains zero-config.
        return EcommerceConnection::query()
            ->where('business_id', $businessId)
            ->where('status', 'connected')
            ->orderBy('id')
            ->limit(2)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    private function connectedAccount(int $id, int $businessId): ?Channel
    {
        return EcommerceConnection::query()
            ->whereKey($id)
            ->where('business_id', $businessId)
            ->where('status', 'connected')
            ->first();
    }
}
