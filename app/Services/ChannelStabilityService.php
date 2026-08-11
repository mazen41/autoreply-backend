<?php

namespace App\Services;

use App\Models\Channel;
use App\Models\Message;
use App\Models\SystemHealthLog;
use App\Jobs\RetryFailedMessage;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class ChannelStabilityService
{
    /**
     * Check channel health and stability
     */
    public function checkChannelHealth(Channel $channel): array
    {
        $health = [
            'status' => 'healthy',
            'issues' => [],
            'metrics' => [],
        ];

        // Check connection status
        if ($channel->status !== 'connected') {
            $health['status'] = 'unhealthy';
            $health['issues'][] = 'Channel not connected';
        }

        // Check recent message failure rate
        $recentMessages = Message::whereHas('conversation', function ($q) use ($channel) {
                $q->where('channel_id', $channel->id);
            })
            ->where('created_at', '>=', now()->subHours(1))
            ->get();

        if ($recentMessages->count() > 0) {
            $failedMessages = $recentMessages->where('delivery_status', 'failed')->count();
            $failureRate = ($failedMessages / $recentMessages->count()) * 100;

            $health['metrics']['failure_rate'] = $failureRate;
            $health['metrics']['total_messages'] = $recentMessages->count();
            $health['metrics']['failed_messages'] = $failedMessages;

            if ($failureRate > 20) {
                $health['status'] = 'degraded';
                $health['issues'][] = "High failure rate: {$failureRate}%";
            }

            if ($failureRate > 50) {
                $health['status'] = 'unhealthy';
            }
        }

        // Check if channel is rate limited
        $rateLimitKey = "channel_rate_limit:{$channel->id}";
        $rateLimitCount = Cache::get($rateLimitKey, 0);

        if ($rateLimitCount > 50) {
            $health['status'] = 'degraded';
            $health['issues'][] = 'Rate limit approaching';
            $health['metrics']['rate_limit_count'] = $rateLimitCount;
        }

        return $health;
    }

    /**
     * Auto-reconnect disconnected channels
     */
    public function autoReconnectChannel(Channel $channel): bool
    {
        if ($channel->status === 'connected') {
            return true;
        }

        try {
            $channelType = $channel->type;

            switch ($channelType) {
                case 'facebook':
                case 'instagram':
                    return $this->reconnectMetaChannel($channel);
                
                case 'whatsapp':
                    return $this->reconnectWhatsAppChannel($channel);
                
                default:
                    Log::warning('Auto-reconnect not supported for channel type', ['channel_type' => $channelType]);
                    return false;
            }
        } catch (\Exception $e) {
            Log::error('Auto-reconnect failed', [
                'channel_id' => $channel->id,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Reconnect Meta channel
     */
    private function reconnectMetaChannel(Channel $channel): bool
    {
        $pageAccessToken = $channel->page_access_token;
        $pageId = $channel->page_id;

        $response = Http::get("https://graph.facebook.com/v18.0/{$pageId}", [
            'access_token' => $pageAccessToken,
        ]);

        if ($response->successful()) {
            $channel->update(['status' => 'connected']);
            Log::info('Meta channel reconnected', ['channel_id' => $channel->id]);
            return true;
        }

        return false;
    }

    /**
     * Reconnect WhatsApp channel
     */
    private function reconnectWhatsAppChannel(Channel $channel): bool
    {
        $evolutionService = new \App\Services\EvolutionApiService();
        $isConnected = $evolutionService->checkConnection($channel);

        if ($isConnected) {
            $channel->update(['status' => 'connected']);
            Log::info('WhatsApp channel reconnected', ['channel_id' => $channel->id]);
            return true;
        }

        return false;
    }

    /**
     * Queue failed messages for retry
     */
    public function queueFailedMessagesForRetry(Channel $channel): int
    {
        $failedMessages = Message::whereHas('conversation', function ($q) use ($channel) {
                $q->where('channel_id', $channel->id);
            })
            ->where('delivery_status', 'failed')
            ->where('retry_count', '<', 3)
            ->get();

        $queuedCount = 0;

        foreach ($failedMessages as $message) {
            RetryFailedMessage::dispatch($message->id);
            $queuedCount++;
        }

        Log::info('Failed messages queued for retry', [
            'channel_id' => $channel->id,
            'count' => $queuedCount,
        ]);

        return $queuedCount;
    }

    /**
     * Monitor all channels and take corrective actions
     */
    public function monitorAllChannels(): void
    {
        $channels = Channel::all();

        foreach ($channels as $channel) {
            $health = $this->checkChannelHealth($channel);

            // Log health status
            if ($health['status'] !== 'healthy') {
                SystemHealthLog::create([
                    'component' => 'channel',
                    'status' => $health['status'],
                    'message' => implode(', ', $health['issues']),
                    'details' => [
                        'channel_id' => $channel->id,
                        'channel_type' => $channel->type,
                        'metrics' => $health['metrics'],
                    ],
                ]);
            }

            // Take corrective actions
            if ($health['status'] === 'unhealthy') {
                // Try to reconnect
                $reconnected = $this->autoReconnectChannel($channel);
                
                if ($reconnected) {
                    // Queue failed messages for retry
                    $this->queueFailedMessagesForRetry($channel);
                }
            }
        }
    }

    /**
     * Get channel statistics
     */
    public function getChannelStatistics(Channel $channel): array
    {
        $today = now()->startOfDay();
        $yesterday = now()->subDay()->startOfDay();

        $messagesToday = Message::whereHas('conversation', function ($q) use ($channel) {
                $q->where('channel_id', $channel->id);
            })
            ->where('created_at', '>=', $today)
            ->count();

        $messagesYesterday = Message::whereHas('conversation', function ($q) use ($channel) {
                $q->where('channel_id', $channel->id);
            })
            ->where('created_at', '>=', $yesterday)
            ->where('created_at', '<', $today)
            ->count();

        $failedToday = Message::whereHas('conversation', function ($q) use ($channel) {
                $q->where('channel_id', $channel->id);
            })
            ->where('created_at', '>=', $today)
            ->where('delivery_status', 'failed')
            ->count();

        return [
            'messages_today' => $messagesToday,
            'messages_yesterday' => $messagesYesterday,
            'failed_today' => $failedToday,
            'success_rate_today' => $messagesToday > 0 
                ? round((($messagesToday - $failedToday) / $messagesToday) * 100, 2) 
                : 0,
            'current_status' => $channel->status,
        ];
    }
}
