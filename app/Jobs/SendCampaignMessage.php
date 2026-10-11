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
    private ?string $providerMessageId = null;

    public function __construct(
        public int $campaignLogId,
        public int $priority = 10 // Low priority for campaigns
    ) {
        // Uses default queue connection so standard queue workers process it
    }

    public function handle(): void
    {
        $this->providerMessageId = null;
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

        // If a previous attempt recorded the provider ID but did not finish
        // the campaign bookkeeping, reconcile that accepted delivery instead
        // of sending the same recipient again.
        if ($campaignLog->status === 'sending') {
            if ($campaignLog->provider_message_id) {
                DB::transaction(function () use ($campaignLog) {
                    $updated = CampaignLog::whereKey($campaignLog->id)
                        ->where('status', 'sending')
                        ->whereNotNull('provider_message_id')
                        ->update(['status' => 'sent', 'sent_at' => now()]);

                    if ($updated === 1) {
                        Campaign::whereKey($campaignLog->campaign_id)->increment('sent_count');
                    }
                });
                if ($campaignLog->campaign) {
                    $this->checkCompletion($campaignLog->campaign);
                }
            }

            Log::info('SendCampaignMessage: in-progress recipient delivery was not re-sent', [
                'campaign_log_id' => $campaignLog->id,
                'provider_id_recorded' => (bool) $campaignLog->provider_message_id,
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

        if ($campaign->paused_at !== null
            || app(\App\Services\AutomationRunControl::class)->releaseIfPaused($this, $campaign->business_id)) {
            if ($campaign->paused_at !== null) {
                $this->release(60);
            }
            return;
        }

        $channel = $campaign->channel;

        if ($campaign->status !== 'sending' && $campaign->status !== 'sent') {
            Log::info('SendCampaignMessage: Campaign not in sending state', ['campaign_id' => $campaign->id, 'status' => $campaign->status]);
            return;
        }

        // Claim the recipient with a conditional transition before any
        // external request. Overlapping queue deliveries can both load the
        // queued row, but only one can transition it to sending.
        $claimed = CampaignLog::whereKey($campaignLog->id)
            ->where('status', 'queued')
            ->update(['status' => 'sending']);
        if ($claimed !== 1) {
            Log::info('SendCampaignMessage: recipient claim was already taken', [
                'campaign_log_id' => $campaignLog->id,
                'status' => $campaignLog->fresh()?->status,
            ]);
            return;
        }
        $campaignLog->refresh();

        if (!$channel) {
            $campaignLog->update(['status' => 'failed', 'error_message' => 'Campaign channel no longer exists']);
            $campaign->increment('failed_count');
            $this->checkCompletion($campaign);
            return;
        }

        // Re-check tenant and routing integrity at execution time. Campaigns
        // may outlive channel edits, and queued jobs must never send through a
        // channel or conversation that has since moved to another business.
        if (
            (int) $channel->business_id !== (int) $campaign->business_id
            || (int) $conversation->business_id !== (int) $campaign->business_id
            || (int) $conversation->channel_id !== (int) $channel->id
        ) {
            $reason = 'Campaign channel or conversation belongs to a different business or channel.';
            $campaignLog->update(['status' => 'failed', 'error_message' => $reason]);
            $campaign->increment('failed_count');
            Log::warning('SendCampaignMessage: tenant or channel mismatch blocked', [
                'campaign_id' => $campaign->id,
                'campaign_log_id' => $campaignLog->id,
                'business_id' => $campaign->business_id,
                'channel_id' => $channel->id,
                'conversation_id' => $conversation->id,
            ]);
            $this->checkCompletion($campaign);
            return;
        }

        // Keep the delivery gate aligned with audience preview, including
        // Gmail's sender_email and normalized email consent channel.
        $consent = app(\App\Services\ConsentGate::class)->checkConversation($conversation, 'marketing');
        if (!$consent->allowed) {
            $campaignLog->update([
                'status' => 'failed',
                'error_message' => 'Marketing send blocked: ' . $consent->reason,
            ]);
            $campaign->increment('failed_count');
            Log::info('SendCampaignMessage: consent gate blocked recipient', [
                'campaign_id' => $campaign->id,
                'campaign_log_id' => $campaignLog->id,
                'conversation_id' => $conversation->id,
                'reason' => $consent->reason,
            ]);
            $this->checkCompletion($campaign);
            return;
        }

        if (in_array($channel->type, ['facebook', 'instagram'], true)) {
            $lastInboundAt = Message::where('conversation_id', $conversation->id)
                ->where('direction', 'inbound')
                ->max('created_at');

            if (!$lastInboundAt || now()->subHours(24)->greaterThanOrEqualTo($lastInboundAt)) {
                $reason = 'Meta 24-hour messaging window expired; no approved message tag is configured.';
                $campaignLog->update([
                    'status' => 'failed',
                    'error_message' => $reason,
                ]);
                $campaign->increment('failed_count');
                Campaign::whereKey($campaign->id)
                    ->whereNull('error_message')
                    ->update(['error_message' => $reason]);

                Log::info('SendCampaignMessage: Meta messaging window blocked recipient', [
                    'campaign_id' => $campaign->id,
                    'campaign_log_id' => $campaignLog->id,
                    'conversation_id' => $conversation->id,
                    'channel_id' => $channel->id,
                ]);
                $this->checkCompletion($campaign);
                return;
            }
        }

        // Rate limiting check per channel (max 30 messages per minute per channel)
        $rateLimitKey = "campaign_rate_limit:{$channel->id}";
        if (Cache::get($rateLimitKey, 0) >= 30) {
            if ($this->deferWithoutConsumingRetryBudget($campaignLog)) {
                return;
            }
        }
        Cache::add($rateLimitKey, 0, 60);
        if (Cache::increment($rateLimitKey) > 30) {
            Cache::decrement($rateLimitKey);
            if ($this->deferWithoutConsumingRetryBudget($campaignLog)) {
                return;
            }
        }

        // Server-side {name} personalization. Falls back to a safe generic
        // greeting when the contact has no known name so the literal
        // "{name}" placeholder is never sent to a recipient.
        $personalizedContent = $this->personalize($campaign->message, $conversation);

        // Recheck immediately before creating the outbound record/provider call.
        // If a pause landed while validation was running, return the claim to
        // queued so resume can continue this recipient without consuming it.
        if ($campaign->paused_at !== null
            || app(\App\Services\AutomationRunControl::class)->isBusinessPaused((int) $campaign->business_id)) {
            CampaignLog::whereKey($campaignLog->id)->where('status', 'sending')->update(['status' => 'queued']);
            $this->release(60);
            return;
        }

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

        if (app(\App\Services\UsageMeter::class)->blockMessageIfOverLimit($campaign->business, $message)) {
            $reason = $message->fresh()->error_details ?: 'Business plan usage limit reached.';
            $campaignLog->update(['status' => 'failed', 'error_message' => $reason]);
            $campaign->increment('failed_count');
            $this->checkCompletion($campaign);
            return;
        }

        // Send message based on channel type
        $this->gmailSubject = $campaign->name ?: 'Message';
        $success = $this->sendMessageToChannel($channel, $conversation, $message, $campaignLog);

        if ($success) {
            DB::transaction(function () use ($message, $campaign, $campaignLog) {
                $claimed = CampaignLog::whereKey($campaignLog->id)
                    ->where('status', 'sending')
                    ->update([
                        'status' => 'sent',
                        'sent_at' => now(),
                        'provider_message_id' => $this->providerMessageId,
                    ]);

                if ($claimed === 1) {
                    $message->update(['send_status' => 'sent']);
                    Campaign::whereKey($campaign->id)->increment('sent_count');
                }
            });

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

    public function retryUntil(): \DateTimeInterface
    {
        return now()->addDays(14);
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
            $sent = (int) ($campaign->sent_count ?? 0);
            $failed = (int) ($campaign->failed_count ?? 0);
            $finalStatus = $sent > 0 && $failed > 0
                ? 'partially_sent'
                : ($sent > 0 ? 'sent' : 'failed');

            Campaign::where('id', $campaign->id)
                ->where('status', 'sending')
                ->update(['status' => $finalStatus]);
        }
    }

    /**
     * Re-dispatch throttled recipients as a fresh delayed job. Queue releases
     * increment the current job's attempt count, so sustained traffic could
     * exhaust $tries without ever attempting a provider send. The sync driver
     * cannot honor delays; it processes serially in-process, so it continues
     * without applying this queue-worker throttle.
     */
    private function deferWithoutConsumingRetryBudget(CampaignLog $campaignLog): bool
    {
        if (config('queue.default') === 'sync') {
            return false;
        }

        $campaignLog->update(['status' => 'queued']);
        self::dispatch($campaignLog->id, $this->priority)
            ->onConnection($this->connection)
            ->onQueue($this->queue)
            ->delay(now()->addSeconds(5));

        Log::info('SendCampaignMessage: recipient deferred by rate limit as a fresh job', [
            'campaign_id' => $campaignLog->campaign_id,
            'campaign_log_id' => $campaignLog->id,
            'delay_seconds' => 5,
        ]);

        return true;
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
                    return $this->sendWhatsAppMessage($channel, $senderId, $message, $campaignLog);

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
                'exception_type' => get_class($e),
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
            ]);
        }

        if ($response->successful()) {
            $this->providerMessageId = $response->json('message_id')
                ?? $response->json('messages.0.id');
        }

        return $response->successful();
    }

    /**
     * WhatsApp campaign send — reuses EvolutionApiService, the same service
     * ProcessAutoReply/SendProactiveMessage use for real WhatsApp sends, so
     * campaigns share the retry/logging/config behaviour with every other
     * outbound WhatsApp path instead of duplicating a second HTTP client.
     */
    private function sendWhatsAppMessage(Channel $channel, string $recipientId, Message $message, CampaignLog $campaignLog): bool
    {
        try {
            if (($channel->metadata['whatsapp_provider'] ?? null) === 'cloud') {
                $campaign = $campaignLog->campaign;
                $template = $campaign?->whatsappCloudTemplate;
                if (!$template || $template->channel_id !== $channel->id || $template->business_id !== $channel->business_id || $template->status !== 'APPROVED') {
                    $campaignLog->update(['error_message' => 'An approved WhatsApp Cloud API template is required.']);
                    return false;
                }

                $result = app(\App\Services\WhatsAppCloudApiService::class)->sendTemplate(
                    $channel,
                    $recipientId,
                    $template->name,
                    $template->language,
                    $campaign->whatsapp_template_components ?? []
                );
                if (!empty($result['success'])) {
                    if (!empty($result['message_id'])) {
                        $this->providerMessageId = (string) $result['message_id'];
                        $message->update(['whatsapp_message_id' => $result['message_id']]);
                    }
                    return true;
                }

                $campaignLog->update(['error_message' => 'WhatsApp Cloud API rejected the approved template send.']);
                return false;
            }

            if (($channel->metadata['unofficial_risk_accepted'] ?? false) !== true) {
                $campaignLog->update(['error_message' => 'Unofficial WhatsApp campaigns are disabled until risk acknowledgment is recorded.']);
                return false;
            }

            // instance_name is stored in page_id for WhatsApp channels
            $instanceName = $channel->page_id;
            if (!$instanceName) {
                $err = 'WhatsApp channel missing instance name (page_id)';
                Log::error('SendCampaignMessage: ' . $err, ['channel_id' => $channel->id]);
                $campaignLog->update(['error_message' => $err]);
                return false;
            }

            $whatsappService = new EvolutionApiService();
            $response = $whatsappService->sendTextMessage($instanceName, $recipientId, $message->content);

            if (isset($response['key']['id'])) {
                $this->providerMessageId = (string) $response['key']['id'];
                return true;
            }

            $campaignLog->update(['error_message' => 'Evolution provider did not confirm message delivery.']);
            return false;
        } catch (\Exception $e) {
            $errMsg = 'WhatsApp send exception (' . get_class($e) . ')';
            Log::error('SendCampaignMessage: WhatsApp send exception', [
                'exception_type' => get_class($e),
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
            $botToken = $channel->access_token;
        } catch (\Exception $e) {
            Log::error('SendCampaignMessage: could not decrypt Telegram bot token', [
                'channel_id' => $channel->id,
                'exception_type' => get_class($e),
            ]);
            return false;
        }

        $telegramService = new TelegramService();
        $providerMessageId = null;
        $sent = $telegramService->sendMessage($botToken, $chatId, $content, $providerMessageId);
        if ($sent) {
            $this->providerMessageId = $providerMessageId;
        }

        return $sent;
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

            $this->providerMessageId = $sent->getId() ? (string) $sent->getId() : null;
            return (bool) $this->providerMessageId;
        } catch (\Exception $e) {
            Log::error('SendCampaignMessage: Gmail send exception', [
                'exception_type' => get_class($e),
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
            'error_message' => 'Campaign message delivery failed.',
        ]);

        $campaign = $campaignLog->campaign;
        if ($campaign) {
            $campaign->increment('failed_count');
            $this->checkCompletion($campaign);
        }

        Log::error('SendCampaignMessage: job failed permanently after retries', [
            'campaign_log_id' => $this->campaignLogId,
            'exception_type' => get_class($exception),
        ]);
    }
}
