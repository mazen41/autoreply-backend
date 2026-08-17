<?php

namespace App\Jobs;

use App\Http\Controllers\GmailController;
use App\Models\Campaign;
use App\Models\CampaignLog;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Channel;
use App\Services\EvolutionApiService;
use App\Services\TelegramService;
use Google\Service\Gmail;
use Google\Service\Gmail\Message as GmailMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class SendCampaignMessage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;
    public $backoff = 60;
    public $timeout = 120;

    /** Subject line used for Gmail campaign sends; set from the campaign name in handle(). */
    private string $gmailSubject = 'Message';

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

        // Idempotency guard: a log already marked sent/failed has already been
        // processed to completion. Without this, a job that is retried after
        // the send succeeded but before the DB update committed (or a stray
        // duplicate dispatch) would send the same message to the same
        // recipient a second time.
        if (in_array($campaignLog->status, ['sent', 'failed'], true)) {
            Log::info('SendCampaignMessage: log already processed, skipping', [
                'campaign_log_id' => $this->campaignLogId,
                'status' => $campaignLog->status,
            ]);
            return;
        }

        $campaign = $campaignLog->campaign;
        $conversation = $campaignLog->conversation;

        if (!$campaign || !$conversation) {
            Log::warning('SendCampaignMessage: missing campaign or conversation', [
                'campaign_log_id' => $this->campaignLogId,
            ]);
            $campaignLog->update(['status' => 'failed', 'error_message' => 'Campaign or conversation no longer exists']);
            return;
        }

        $channel = $campaign->channel;

        if ($campaign->status !== 'sending' && $campaign->status !== 'sent') {
            Log::info('SendCampaignMessage: Campaign not in sending state', ['campaign_id' => $campaign->id, 'status' => $campaign->status]);
            return;
        }

        if (!$channel) {
            $campaignLog->update(['status' => 'failed', 'error_message' => 'Campaign channel no longer exists']);
            $campaign->increment('failed_count');
            $this->checkCompletion($campaign);
            return;
        }

        // Rate limiting check per channel (atomic increment avoids the
        // read-then-write race that let concurrent workers all pass the
        // check at once and blow past the per-minute cap).
        $rateLimitKey = "campaign_rate_limit:{$channel->id}";
        if (Cache::get($rateLimitKey, 0) >= 10) { // Max 10 messages per minute per channel
            $this->release(15); // Retry shortly, within the same minute window
            return;
        }
        // Cache::add() seeds the 60s window key atomically only if it does
        // not already exist; Cache::increment() then bumps it atomically.
        // Together this avoids the read-then-write race where two workers
        // both read "9" and both proceed, blowing past the cap of 10.
        Cache::add($rateLimitKey, 0, 60);
        if (Cache::increment($rateLimitKey) > 10) {
            $this->release(15);
            return;
        }

        // Server-side {name} personalization. Falls back to a safe generic
        // greeting when the contact has no known name so the literal
        // "{name}" placeholder is never sent to a recipient.
        $personalizedContent = $this->personalize($campaign->message, $conversation);

        // Create message
        $message = Message::create([
            'conversation_id' => $conversation->id,
            'content' => $personalizedContent,
            'direction' => 'outbound',
            'status' => 'campaign',
            'is_ai' => false,
            'source' => 'campaign',
            'send_status' => 'pending',
        ]);

        // Send message based on channel type
        $this->gmailSubject = $campaign->name ?: 'Message';
        $success = $this->sendMessageToChannel($channel, $conversation, $message, $campaignLog);

        if ($success) {
            $message->update(['send_status' => 'sent']);
            $campaignLog->update([
                'status' => 'sent',
                'sent_at' => now(),
            ]);

            $campaign->increment('sent_count');

            Log::info('SendCampaignMessage: Message sent successfully', [
                'campaign_log_id' => $this->campaignLogId,
                'conversation_id' => $conversation->id,
            ]);
        } else {
            $message->update(['send_status' => 'failed']);
            $campaignLog->update([
                'status' => 'failed',
                'error_message' => $campaignLog->error_message ?: 'Failed to send message to channel',
            ]);

            $campaign->increment('failed_count');

            Log::error('SendCampaignMessage: Failed to send message', [
                'campaign_log_id' => $this->campaignLogId,
                'conversation_id' => $conversation->id,
            ]);
        }

        $this->checkCompletion($campaign);
    }

    /**
     * Replace the {name} personalization token with the recipient's known
     * name. Falls back to a safe generic greeting so the literal token is
     * never sent, and strips any stray token if replacement still fails.
     */
    private function personalize(string $content, Conversation $conversation): string
    {
        $name = trim((string) ($conversation->sender_name ?? ''));

        // Guard against placeholder-looking or numeric-only "names" (e.g. a
        // raw phone number stored as sender_name) — those aren't a real name
        // either, so fall back too.
        if ($name === '' || $name === '.' || preg_match('/^\+?\d+$/', $name)) {
            $name = 'there';
        }

        $replaced = preg_replace('/\{\s*name\s*\}/i', $name, $content) ?? $content;

        // Final safety net: never let a literal {name} reach a recipient.
        return preg_replace('/\{\s*name\s*\}/i', 'there', $replaced) ?? $replaced;
    }

    /**
     * Flip the campaign to its final status once every recipient has been
     * attempted. Uses an atomic, conditional update (only from 'sending')
     * so two workers finishing the last two logs at the same instant can't
     * both run this and race — the second update simply affects 0 rows.
     */
    private function checkCompletion(Campaign $campaign): void
    {
        $campaign->refresh();
        $totalProcessed = ($campaign->sent_count ?? 0) + ($campaign->failed_count ?? 0);

        if ($campaign->total_recipients > 0 && $totalProcessed >= $campaign->total_recipients) {
            $finalStatus = ($campaign->sent_count ?? 0) > 0 ? 'sent' : 'failed';

            Campaign::where('id', $campaign->id)
                ->where('status', 'sending')
                ->update(['status' => $finalStatus]);
        }
    }

    private function sendMessageToChannel(Channel $channel, Conversation $conversation, Message $message, CampaignLog $campaignLog): bool
    {
        try {
            $channelType = $channel->type;
            $senderId = $conversation->sender_id;

            switch ($channelType) {
                case 'facebook':
                case 'instagram':
                    return $this->sendMetaMessage($channel, $senderId, $message->content);

                case 'whatsapp':
                    return $this->sendWhatsAppMessage($channel, $senderId, $message->content, $campaignLog);

                case 'telegram':
                    return $this->sendTelegramMessage($channel, $senderId, $message->content);

                case 'gmail':
                    return $this->sendGmailMessage($channel, $conversation, $message);

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
        $accessToken = $channel->access_token ?? $channel->page_access_token;
        $url = "https://graph.facebook.com/v19.0/me/messages?access_token={$accessToken}";

        $response = Http::timeout(10)->post($url, [
            'recipient' => ['id' => $recipientId],
            'message' => ['text' => $content],
        ]);

        if (!$response->successful()) {
            Log::error('SendCampaignMessage: Meta send failed', [
                'status' => $response->status(),
                'body' => $response->json(),
            ]);
        }

        return $response->successful();
    }

    /**
     * WhatsApp campaign send — reuses EvolutionApiService, the same service
     * ProcessAutoReply/SendProactiveMessage use for real WhatsApp sends, so
     * campaigns share the retry/logging/config behaviour with every other
     * outbound WhatsApp path instead of duplicating a second HTTP client.
     */
    private function sendWhatsAppMessage(Channel $channel, string $recipientId, string $content, CampaignLog $campaignLog): bool
    {
        try {
            // instance_name is stored in page_id for WhatsApp channels
            $instanceName = $channel->page_id;
            if (!$instanceName) {
                $err = 'WhatsApp channel missing instance name (page_id)';
                Log::error('SendCampaignMessage: ' . $err, ['channel_id' => $channel->id]);
                $campaignLog->update(['error_message' => $err]);
                return false;
            }

            $whatsappService = new EvolutionApiService();
            $response = $whatsappService->sendTextMessage($instanceName, $recipientId, $content);

            if (isset($response['key']['id'])) {
                return true;
            }

            $errMsg = 'Evolution API error: ' . json_encode($response);
            $campaignLog->update(['error_message' => substr($errMsg, 0, 500)]);
            return false;
        } catch (\Exception $e) {
            $errMsg = 'WhatsApp send exception: ' . $e->getMessage();
            Log::error('SendCampaignMessage: ' . $errMsg, [
                'error' => $e->getMessage(),
                'channel_id' => $channel->id,
            ]);
            $campaignLog->update(['error_message' => substr($errMsg, 0, 500)]);
            return false;
        }
    }

    /**
     * Telegram campaign send — reuses TelegramService, the same service used
     * elsewhere for bot messaging. Bot token is stored encrypted on the
     * channel (access_token); recipient chat_id is the conversation's
     * sender_id, exactly as TelegramController::webhook() stores it.
     */
    private function sendTelegramMessage(Channel $channel, string $chatId, string $content): bool
    {
        try {
            $botToken = decrypt($channel->access_token);
        } catch (\Exception $e) {
            Log::error('SendCampaignMessage: could not decrypt Telegram bot token', [
                'channel_id' => $channel->id,
                'error' => $e->getMessage(),
            ]);
            return false;
        }

        $telegramService = new TelegramService();

        return $telegramService->sendMessage($botToken, $chatId, $content);
    }

    /**
     * Gmail campaign send — reuses GmailController::getAuthenticatedClient
     * (the same OAuth token refresh logic ProcessAutoReply::sendGmailReply
     * uses) and sends a real Gmail API message via Google\Service\Gmail,
     * rather than the previous unconditional `return false` stub.
     */
    private function sendGmailMessage(Channel $channel, Conversation $conversation, Message $message): bool
    {
        $to = $conversation->sender_email;
        if (!$to) {
            Log::error('SendCampaignMessage: conversation has no sender_email for Gmail campaign', [
                'conversation_id' => $conversation->id,
            ]);
            return false;
        }

        $gmailCtrl = new GmailController();
        $client = $gmailCtrl->getAuthenticatedClient($channel);

        if (!$client) {
            Log::error('SendCampaignMessage: could not get Gmail client', ['channel_id' => $channel->id]);
            return false;
        }

        try {
            $gmail = new Gmail($client);

            $subject = $this->gmailSubject;
            $body = $message->content;

            $raw = "To: {$to}\r\n";
            $raw .= "Subject: {$subject}\r\n";
            $raw .= "Content-Type: text/plain; charset=utf-8\r\n\r\n";
            $raw .= $body;

            $encoded = rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');

            $gmailMessage = new GmailMessage();
            $gmailMessage->setRaw($encoded);

            $sent = $gmail->users_messages->send('me', $gmailMessage);

            return (bool) $sent->getId();
        } catch (\Exception $e) {
            Log::error('SendCampaignMessage: Gmail send exception', [
                'error' => $e->getMessage(),
                'channel_id' => $channel->id,
            ]);
            return false;
        }
    }


    /**
     * Called by Laravel once all $tries attempts are exhausted. Without this,
     * a permanently-failing send (e.g. a dead channel token) left the
     * CampaignLog stuck on 'queued' forever — it was never marked 'failed',
     * so the campaign's sent+failed count could never reach total_recipients
     * and the campaign would stay "sending" indefinitely with an inaccurate
     * failed-recipient count.
     */
    public function failed(\Throwable $exception): void
    {
        $campaignLog = CampaignLog::with('campaign')->find($this->campaignLogId);

        if (!$campaignLog || in_array($campaignLog->status, ['sent', 'failed'], true)) {
            return;
        }

        $campaignLog->update([
            'status' => 'failed',
            'error_message' => $exception->getMessage(),
        ]);

        $campaign = $campaignLog->campaign;
        if ($campaign) {
            $campaign->increment('failed_count');
            $this->checkCompletion($campaign);
        }

        Log::error('SendCampaignMessage: job failed permanently after retries', [
            'campaign_log_id' => $this->campaignLogId,
            'error' => $exception->getMessage(),
        ]);
    }
}
