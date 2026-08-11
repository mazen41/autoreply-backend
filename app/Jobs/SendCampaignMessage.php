<?php

namespace App\Jobs;

use App\Models\Campaign;
use App\Models\CampaignLog;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Channel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class SendCampaignMessage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;
    public $backoff = 60;
    public $timeout = 120;

    public function __construct(
        public int $campaignLogId,
        public int $priority = 10 // Low priority for campaigns
    ) {
        $this->onQueue('campaigns'); // Separate queue for campaigns
    }

    public function handle(): void
    {
        $campaignLog = CampaignLog::with(['campaign.channel', 'conversation'])
            ->find($this->campaignLogId);

        if (!$campaignLog) {
            Log::warning('SendCampaignMessage: Campaign log not found', ['campaign_log_id' => $this->campaignLogId]);
            return;
        }

        $campaign = $campaignLog->campaign;
        $conversation = $campaignLog->conversation;
        $channel = $campaign->channel;

        if ($campaign->status !== 'sending' && $campaign->status !== 'sent') {
            Log::info('SendCampaignMessage: Campaign not in sending state', ['campaign_id' => $campaign->id, 'status' => $campaign->status]);
            return;
        }

        // Rate limiting check per channel
        $rateLimitKey = "campaign_rate_limit:{$channel->id}";
        $rateLimit = Cache::get($rateLimitKey, 0);
        
        if ($rateLimit >= 10) { // Max 10 messages per minute per channel
            $this->release(60); // Retry after 1 minute
            return;
        }

        // Create message
        $message = Message::create([
            'conversation_id' => $conversation->id,
            'content' => $campaign->message,
            'direction' => 'outbound',
            'status' => 'campaign',
            'is_ai' => false,
            'source' => 'campaign',
            'send_status' => 'pending',
        ]);

        // Send message based on channel type
        $success = $this->sendMessageToChannel($channel, $conversation, $message);

        if ($success) {
            $campaignLog->update([
                'status' => 'sent',
                'sent_at' => now(),
            ]);

            $campaign->increment('sent_count');
            
            // Increment rate limit counter
            Cache::put($rateLimitKey, $rateLimit + 1, 60);
            
            Log::info('SendCampaignMessage: Message sent successfully', [
                'campaign_log_id' => $this->campaignLogId,
                'conversation_id' => $conversation->id,
            ]);
        } else {
            $campaignLog->update([
                'status' => 'failed',
                'error_message' => 'Failed to send message to channel',
            ]);

            $campaign->increment('failed_count');
            
            Log::error('SendCampaignMessage: Failed to send message', [
                'campaign_log_id' => $this->campaignLogId,
                'conversation_id' => $conversation->id,
            ]);
        }
    }

    private function sendMessageToChannel(Channel $channel, Conversation $conversation, Message $message): bool
    {
        try {
            $channelType = $channel->type;
            $senderId = $conversation->sender_id;

            switch ($channelType) {
                case 'facebook':
                case 'instagram':
                    return $this->sendMetaMessage($channel, $senderId, $message->content);
                
                case 'whatsapp':
                    return $this->sendWhatsAppMessage($channel, $senderId, $message->content);
                
                case 'gmail':
                    // Gmail campaigns are different - handled separately
                    return false;
                
                default:
                    Log::warning('SendCampaignMessage: Unsupported channel type', ['channel_type' => $channelType]);
                    return false;
            }
        } catch (\Exception $e) {
            Log::error('SendCampaignMessage: Exception', [
                'error' => $e->getMessage(),
                'channel_id' => $channel->id,
            ]);
            return false;
        }
    }

    private function sendMetaMessage(Channel $channel, string $recipientId, string $content): bool
    {
        $pageAccessToken = $channel->page_access_token;
        $pageId = $channel->page_id;

        $response = Http::post("https://graph.facebook.com/v18.0/{$pageId}/messages", [
            'recipient' => ['id' => $recipientId],
            'message' => ['text' => $content],
        ], [
            'headers' => [
                'Authorization' => "Bearer {$pageAccessToken}",
            ],
        ]);

        return $response->successful();
    }

    private function sendWhatsAppMessage(Channel $channel, string $recipientId, string $content): bool
    {
        // WhatsApp implementation using Evolution API or similar
        // This would need to be implemented based on your WhatsApp service
        return false;
    }
}
