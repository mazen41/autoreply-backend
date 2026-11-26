<?php

namespace App\Jobs;

use App\Models\Channel;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\HandoffTask;
use App\Http\Controllers\GmailController;
use App\Services\EvolutionApiService;
use App\Services\KnowledgeChunker;
use App\Services\AICapabilitiesService;
use App\Services\SallaService;
use App\Services\ArabicDialectService;
use App\Services\BusinessHoursService;
use App\Services\ProductAwarenessService;
use App\Services\SequenceTriggerService;
use App\Services\OrderCheckoutService;
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
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Mail;

class ProcessAutoReply implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;
    public $backoff = 30;

    // FIX (2026-08-31): no job-level timeout was set, so a hang anywhere in
    // handle() (most commonly the Gemini chat/embedding HTTP calls, now given
    // their own explicit timeouts) could run past the queue connection's
    // retry_after window (90s, see config/queue.php 'database'.'retry_after').
    // When that happens Laravel assumes the worker died and makes the SAME
    // job available again — NOT a clean retry via $tries/$backoff, but a
    // duplicate/possibly-concurrent execution. Production logs showed exactly
    // this: "ProcessAutoReply job started" for the same message id 2-3 times,
    // ~90s apart, before one attempt finally completed. Setting an explicit
    // timeout comfortably below 90s means a hang now fails the job cleanly
    // and lets $tries/$backoff handle the retry as intended, instead of the
    // queue's reservation-expiry silently duplicating it.
    public $timeout = 75;

    public int $aiRepliesCount = 0;

    public function __construct(public int $messageId)
    {
    }

    public function handle(): void
    {
        Log::info('ProcessAutoReply job started', ['message_id' => $this->messageId]);

        $message = Message::with(['conversation.channel', 'conversation.channel.business', 'conversation.channel.user'])
            ->find($this->messageId);

        if (!$message) {
            Log::warning('ProcessAutoReply: message not found', ['message_id' => $this->messageId]);
            return;
        }

        $channel = $message->conversation->channel;
        $conversation = $message->conversation;

        if ($channel?->type === 'tiktok') {
            Log::warning('ProcessAutoReply: TikTok messaging is unsupported; skipping automated reply', [
                'channel_id' => $channel->id,
                'conversation_id' => $conversation?->id,
                'message_id' => $message->id,
            ]);
            return;
        }

        if (!$channel) {
            Log::info('ProcessAutoReply: channel not found for message', ['message_id' => $message->id]);
            return;
        }

        // ai_enabled on Conversation lets agents disable AI per-conversation (e.g. after escalation)
        if (!$conversation) {
            Log::info('ProcessAutoReply: conversation not found', ['message_id' => $this->messageId]);
            return;
        }

        if ($conversation->is_blocked) {
            Log::info('ProcessAutoReply: blocked WhatsApp contact; skipped automated reply', [
                'conversation_id' => $conversation->id,
                'message_id' => $message->id,
            ]);
            return;
        }

        // Default true if column is null (old rows before migration)
        if ($conversation->ai_enabled === false) {
            Log::info('ProcessAutoReply: AI disabled for this conversation (human took over)', [
                'conversation_id' => $conversation->id,
            ]);
            return;
        }

        // Escalation itself is a hard pause, even if a legacy escalation path
        // did not also persist ai_enabled=false. Only the explicit handled
        // action clears requires_human and resumes future customer turns.
        if ($conversation->requires_human) {
            // The bot stays paused for the human, but the customer must never be left
            // without a reply. Send one short holding message per 10 minutes per conversation.
            Log::info('ProcessAutoReply: conversation is waiting for human handling', [
                'conversation_id' => $conversation->id,
                'message_id' => $message->id,
            ]);
            if (Cache::add('handoff_hold_reply:' . $conversation->id, 1, now()->addMinutes(10))) {
                $holdingArabic = $this->detectLanguage($message->content) === 'arabic';
                $holdingReply = Message::create([
                    'conversation_id' => $conversation->id,
                    'content' => $holdingArabic
                        ? 'فريقنا سيرد عليك قريباً. شكراً لصبرك.'
                        : 'A team member will reply to you shortly. Thank you for your patience.',
                    'direction' => 'outbound',
                    'status' => 'auto',
                    'is_ai' => false,
                    'source' => 'handoff_holding_reply',
                    'send_status' => 'pending',
                ]);
                if ($channel->user_id) {
                    broadcast(new \App\Events\MessageReceived($holdingReply, $conversation, $channel->user_id));
                }
                $this->sendReply($channel, $conversation, $holdingReply);
            }
            return;
        }


        // Global human-only/emergency pause must also cover inbound AI replies
        // and deterministic attachment acknowledgements. Drop this turn while
        // paused so queued messages are never sent late after an operator
        // resumes automation; new customer messages after resume are handled
        // normally.
        $business = $conversation->business ?? $channel?->business;
        if ($business && app(\App\Services\AutomationRunControl::class)->isBusinessPaused($business)) {
            Log::info('ProcessAutoReply: customer-facing automation paused; message left for human handling', [
                'business_id' => $business->id,
                'conversation_id' => $conversation->id,
                'message_id' => $message->id,
            ]);
            return;
        }

        if ($channel->status !== 'connected') {
            Log::warning('ProcessAutoReply: channel not connected', ['channel_id' => $channel->id, 'status' => $channel->status]);
            return;
        }

        // An attachment without accompanying text has a deterministic reply.
        // Handle it before business-hours, sequences, commerce resolution, or
        // provider calls so it cannot trigger an AI turn or duplicate checkout
        // summary while an order is in progress.
        if (trim((string) $message->content) === ''
            && data_get($message->metadata, 'attachment.status') === 'attachment_without_text') {
            $lock = Cache::store('database')->lock('attachment_without_text_reply:' . $message->id, 60);
            if (!$lock->get()) {
                Log::info('ProcessAutoReply: attachment acknowledgement already in progress', [
                    'message_id' => $message->id,
                ]);
                return;
            }

            try {
                $reply = Message::query()
                    ->where('conversation_id', $conversation->id)
                    ->where('direction', 'outbound')
                    ->where('source', 'attachment_without_text')
                    ->get()
                    ->first(fn (Message $candidate) => (int) data_get($candidate->metadata, 'attachment_ack_for_message_id') === $message->id);

                if ($reply?->send_status === 'sent') {
                    return;
                }

                $reply ??= Message::create([
                    'conversation_id' => $conversation->id,
                    'content' => \App\Services\ConfirmationGuard::attachmentWithoutTextReply(false),
                    'direction' => 'outbound',
                    'status' => 'auto',
                    'is_ai' => false,
                    'source' => 'attachment_without_text',
                    'send_status' => 'pending',
                    'metadata' => ['attachment_ack_for_message_id' => $message->id],
                ]);

                if ($channel->user_id) {
                    broadcast(new \App\Events\MessageReceived($reply, $conversation, $channel->user_id));
                }
                $this->sendReply($channel, $conversation, $reply);
            } finally {
                $lock->release();
            }

            return;
        }

        // Resolve business profile — Conversation has no direct business_id FK,
        // so we load it exclusively from the channel. If it's null the channel
        // was never linked to a business profile (data-integrity gap) — bail
        // gracefully without retrying (retrying won't fix a missing FK).
        $business = $channel->business;
        if (!$business) {
            Log::error('ProcessAutoReply: channel has no linked business profile — aborting (no retry)', [
                'message_id'      => $this->messageId,
                'channel_id'      => $channel->id,
                'channel_type'    => $channel->type,
                'conversation_id' => $conversation->id,
            ]);
            $this->fail(new \RuntimeException("Channel {$channel->id} has no business profile — permanent failure"));
            return;
        }

        // Legacy alias kept for downstream code that still uses $conversation->business
        $business = $conversation->business ?? $channel->business;
        if ($business) {
            $businessHoursService = new BusinessHoursService();
            
            if (!$businessHoursService->isBusinessOpen($business)) {
                $awayMessage = $businessHoursService->getAwayMessage($business);
                
                if ($awayMessage) {
                    Log::info('ProcessAutoReply: Business closed, sending away message', [
                        'conversation_id' => $conversation->id,
                        'business_id' => $business->id,
                    ]);

                    // Send away message
                    $replyMessage = Message::create([
                        'conversation_id' => $conversation->id,
                        'content' => $awayMessage,
                        'direction' => 'outbound',
                        'status' => 'auto',
                        'is_ai' => false,
                        'source' => 'away_message',
                        'send_status' => 'pending',
                    ]);

                    // Send the away message through the channel
                    $this->sendReply($channel, $conversation, $replyMessage);
                    
                    // Skip AI processing if configured
                    if ($businessHoursService->shouldDisableAI($business)) {
                        return;
                    }
                }
            }
        }

        $user = $channel->user;
        if (!$user) {
            Log::warning('ProcessAutoReply: channel has no user', ['channel_id' => $channel->id]);
            return;
        }

        $business ??= $conversation->business;
        if (!$business) {
            Log::warning('ProcessAutoReply: business is unavailable for usage enforcement', [
                'channel_id' => $channel->id,
                'conversation_id' => $conversation->id,
            ]);
            return;
        }

        $usageMeter = app(\App\Services\UsageMeter::class);
        $aiUsage = $usageMeter->canUse($business, \App\Services\UsageMeter::AI_REPLIES);
        $outboundUsage = $usageMeter->canUse($business, \App\Services\UsageMeter::OUTBOUND_MESSAGES);
        if (!$aiUsage['allowed'] || !$outboundUsage['allowed']) {
            Log::info('ProcessAutoReply: business plan usage limit reached', [
                'business_id' => $business->id,
                'channel_id' => $channel->id,
                'conversation_id' => $conversation->id,
                'metric' => !$aiUsage['allowed'] ? 'ai_replies' : 'outbound_messages',
            ]);
            if (!$aiUsage['allowed']) {
                $this->notifyInsufficientAiCredits($business, $conversation, $message);
            }
            return;
        }
        $this->aiRepliesCount = max(0, $aiUsage['used']) + 1;

        // ── NEW-USER SEQUENCE PRIORITY CHECK ─────────────────────────────────
        // This MUST happen before the AI flow. If the incoming message is from a
        // new user AND an active sequence with trigger_type='new_user' exists for
        // this business, the sequence takes full ownership of the response.
        // The AI auto-reply is skipped entirely for that message so the customer
        // never receives both a sequence greeting and an AI reply.
        //
        // "New user" is defined as: this inbound message is the FIRST inbound
        // message ever recorded on this conversation. We count inbound messages
        // on the conversation rather than checking whether the Conversation row
        // itself is new, because the conversation row is created before any
        // message processing begins (by the webhook handler).
        //
        // Idempotency: SequenceEnrollmentService::enrollConversation() checks for
        // an existing active enrollment before creating one. Additionally, we use
        // a per-conversation cache lock so that job retries for the same message
        // do not attempt a second enrollment.
        if ($business) {
            // When a customer sends an inbound message, cancel any active no-reply
            // sequence enrollments. Pending delayed jobs for older cycles are
            // invalidated automatically via the cycle-number gate in CheckNoReplyForMessage.
            try {
                app(SequenceTriggerService::class)->cancelNoReplySequencesOnCustomerReply($conversation);
            } catch (\Exception $e) {
                Log::warning('ProcessAutoReply: failed to cancel no-reply sequences on customer reply', [
                    'conversation_id' => $conversation->id,
                    'exception_type' => get_class($e),
                ]);
            }

            $isNewUser = $this->isNewUserConversation($conversation, $message);

            Log::info('ProcessAutoReply: new-user check', [
                'conversation_id' => $conversation->id,
                'message_id'      => $message->id,
                'is_new_user'     => $isNewUser,
            ]);

            if ($isNewUser) {
                $sequenceTriggered = $this->tryTriggerNewUserSequence($conversation, $business->id);

                if ($sequenceTriggered) {
                    // Sequence owns this response. Do NOT run the AI auto-reply.
                    Log::info('ProcessAutoReply: new-user sequence triggered — skipping AI auto-reply', [
                        'conversation_id' => $conversation->id,
                        'message_id'      => $message->id,
                    ]);
                    return;
                }

                Log::info('ProcessAutoReply: no new-user sequence matched — continuing normal AI flow', [
                    'conversation_id' => $conversation->id,
                ]);
            }
        }
        // ── END NEW-USER SEQUENCE PRIORITY CHECK ──────────────────────────────

        $detectedLanguage = $this->detectLanguage($message->content);

        // ── VOICE MESSAGE TRANSCRIPTION ────────────────────────────────────
        $voiceTranscription = null;
        if (config('attachments.read_enabled', false) && in_array($message->type ?? '', ['voice', 'audio']) && $message->media_url) {
            try {
                $voiceService = new \App\Services\VoiceService();
                $audioPath = app(\App\Services\IncomingMediaStorage::class)->absolutePath(
                    $message->media_url,
                    data_get($message->metadata, 'media.storage_disk')
                );
                if (!$audioPath) {
                    throw new \RuntimeException('Inbound audio is unavailable in private storage.');
                }

                $voiceTranscription = $voiceService->transcribeAudio(
                    $audioPath,
                    $business,
                    \App\Services\AiGateway::stableOperationId('voice-transcription:' . $message->id),
                    is_numeric(data_get($message->metadata, 'media.duration')) ? (int) data_get($message->metadata, 'media.duration') : null,
                );
                if (trim($voiceTranscription) === '') {
                    throw new \RuntimeException('Audio transcription returned no text.');
                }
                $message->content = $voiceTranscription;
                $message->media_processing_status = 'done';
                $message->media_processing_reason = null;
                $message->save();
                Log::info('ProcessAutoReply: voice message transcribed', [
                    'conversation_id' => $conversation->id,
                    'transcription_length' => strlen($voiceTranscription),
                ]);
            } catch (\Throwable $e) {
                $message->media_processing_status = 'failed';
                $message->media_processing_reason = 'Voice note could not be transcribed.';
                $message->save();
                Log::warning('ProcessAutoReply: voice transcription failed', [
                    'conversation_id' => $conversation->id,
                    'error_class' => get_class($e),
                ]);
            }
        }

        // ── IMAGE UNDERSTANDING ─────────────────────────────────────────────
        $imageAnalysis = null;
        if (config('attachments.read_enabled', false) && ($message->type ?? '') === 'image' && $message->media_url) {
            try {
                $imageService = new \App\Services\ImageUnderstandingService();
                $imagePath = app(\App\Services\IncomingMediaStorage::class)->absolutePath(
                    $message->media_url,
                    data_get($message->metadata, 'media.storage_disk')
                );
                if (!$imagePath) {
                    throw new \RuntimeException('Inbound image is unavailable in private storage.');
                }
                $imageAnalysis = $imageService->analyzeImage(
                    $imagePath,
                    'Describe this image and identify any products or receipts',
                    data_get($message->metadata, 'media.storage_disk'),
                    $business,
                    \App\Services\AiGateway::stableOperationId('image-understanding:' . $message->id)
                );

                if (!empty($imageAnalysis['raw_analysis'])) {
                    Log::info('ProcessAutoReply: image analyzed', [
                        'conversation_id' => $conversation->id,
                        'detected_intent' => $imageAnalysis['detected_intent'],
                    ]);

                    // Keep interpreted media data out of customer-authored
                    // content. It is passed to the model as labeled metadata.
                    $metadata = is_array($message->metadata) ? $message->metadata : [];
                    $metadata['attachment_analysis'] = [
                        'description' => (string) $imageAnalysis['raw_analysis'],
                        'extracted_text' => (string) ($imageAnalysis['extracted_text'] ?? ''),
                    ];
                    $message->metadata = $metadata;
                    $message->media_processing_status = 'done';
                    $message->media_processing_reason = null;
                } else {
                    $message->media_processing_status = 'failed';
                    $message->media_processing_reason = 'Image could not be analyzed.';
                }
                $message->save();
            } catch (\Throwable $e) {
                $message->media_processing_status = 'failed';
                $message->media_processing_reason = 'Image could not be analyzed.';
                $message->save();
                Log::warning('ProcessAutoReply: image analysis failed', [
                    'conversation_id' => $conversation->id,
                    'error_class' => get_class($e),
                ]);
            }
        } elseif (!empty($message->media_url) && !in_array($message->type ?? '', ['audio', 'voice', 'image'], true)) {
            $message->media_processing_status = 'unsupported';
            $message->media_processing_reason = 'This media type is not analyzed by the assistant.';
            $message->save();
        }

        // Get last 10 messages for context
        $contextMessages = Message::where('conversation_id', $message->conversation_id)
            ->orderBy('created_at', 'desc')
            ->take(10)
            ->get()
            ->reverse()
            ->map(fn($m) => [
                'role'        => $m->direction === 'inbound' ? 'user' : 'assistant',
                'direction'   => $m->direction,
                'content'     => $m->content,
                'is_ai'       => $m->is_ai,
                'send_status' => $m->send_status,
            ])
            ->toArray();

        // ── GREETING LOOP PREVENTION ────────────────────────────────────────
        // Detect if this is a repeated greeting and inject context to prevent static loops
        $greetingPattern = '/^(hi|hello|hey|مرحبا|السلام عليكم|صباح الخير|مساء الخير|اهلا|هلا)[\s\!\.\,]*$/i';
        $isGreeting = preg_match($greetingPattern, trim($message->content));

        if ($isGreeting) {
            // Count previous greetings in this conversation
            $previousGreetings = Message::where('conversation_id', $message->conversation_id)
                ->where('direction', 'inbound')
                ->whereRaw('LOWER(TRIM(content)) REGEXP ?', ['^(hi|hello|hey|مرحبا|السلام عليكم|صباح الخير|مساء الخير|اهلا|هلا)[\s\!\.\,]*$'])
                ->count();

            if ($previousGreetings > 0) {
                // Add context to prevent repetitive welcome messages
                $contextMessages[] = [
                    'role'        => 'system',
                    'direction'   => 'system',
                    'content'     => "IMPORTANT: The customer has greeted {$previousGreetings} time(s) already in this conversation. Do NOT repeat the same welcome message. Instead, ask how you can help them today, or reference their previous inquiry if one exists. Vary your response naturally.",
                    'is_ai'       => false,
                    'send_status' => 'system',
                ];

                Log::info('ProcessAutoReply: repeated greeting detected, injecting loop prevention', [
                    'conversation_id' => $conversation->id,
                    'previous_greetings' => $previousGreetings,
                ]);
            }
        }

        // Check business hours routing — guard against null business
        $businessHoursCheck = $channel->business
            ? AICapabilitiesService::checkBusinessHours($channel->business)
            : ['should_use_hours_routing' => false];
        if ($businessHoursCheck['should_use_hours_routing']) {
            Log::info('ProcessAutoReply: Business hours routing activated', [
                'conversation_id' => $conversation->id,
                'is_after_hours' => $businessHoursCheck['is_after_hours']
            ]);

            // Send after-hours message and queue for human review
            $afterHoursMessage = $businessHoursCheck['routing_message'];
            $conversation->update([
                'requires_human' => true,
                'ai_enabled' => false,
                'status' => 'pending_human',
                'escalated_at' => now(),
                'escalation_reason' => 'after_hours'
            ]);
            app(\App\Services\HandoffTaskService::class)->openForConversation($conversation, 'after_hours');

            $replyMessage = Message::create([
                'conversation_id' => $message->conversation_id,
                'content' => $afterHoursMessage,
                'direction' => 'outbound',
                'status' => 'auto',
                'is_ai' => true,
                'source' => 'ai_hours_routing',
                'send_status' => 'pending',
            ]);

            try {
                app(\App\Services\AiReplyAuditService::class)->record($replyMessage, [
                    'ai_provider' => 'system',
                    'ai_model' => 'business_hours_router',
                    'prompt_version' => config('services.ai.prompt_version', 'v1'),
                    'escalation_decision' => true,
                    'escalation_reason' => 'after_hours',
                ]);
            } catch (\Throwable $e) {
                Log::error('ProcessAutoReply: could not persist after-hours reply audit', [
                    'message_id' => $replyMessage->id,
                    'exception_type' => get_class($e),
                ]);
            }

            $this->sendReply($channel, $conversation, $replyMessage);
            return;
        }

        // ── BOT RESOLUTION (Deterministic Hierarchy) ──────────────────────────
        $bot = null;
        $botResolutionSource = 'none';
        $invalidStickyBot = false;
        if ($business) {
            try {
                // Priority 1: Existing conversation bot (sticky)
                if (!empty($conversation->bot_id)) {
                    $bot = \App\Models\Bot::where('id', $conversation->bot_id)
                        ->where('business_profile_id', $business->id)
                        ->where('status', 'active')
                        ->first();
                    if ($bot) {
                        $botResolutionSource = 'conversation_snapshot';
                    } else {
                        // Bot exists on conversation but is inactive or from another business
                        $invalidStickyBot = true;
                        Log::warning('ProcessAutoReply: conversation bot_id is invalid or inactive, clearing', [
                            'conversation_id' => $conversation->id,
                            'invalid_bot_id' => $conversation->bot_id,
                            'business_id' => $business->id,
                        ]);
                        $conversation->update(['bot_id' => null]);
                    }
                }

                // Priority 2-4: Resolve from channel's assigned bots
                if (!$bot && method_exists($channel, 'bots')) {
                    $activeBots = $channel->bots()
                        ->where('status', 'active')
                        ->where('business_profile_id', $business->id)
                        ->get();

                    if ($activeBots->count() === 1) {
                        // Priority 3: Exactly one active bot
                        $bot = $activeBots->first();
                        $botResolutionSource = 'channel_assignment';
                    } elseif ($activeBots->count() > 1) {
                        // Priority 2: Check for primary bot
                        $primaryBot = $activeBots->first(function ($b) {
                            return (bool)($b->pivot->is_primary ?? false);
                        });

                        if ($primaryBot) {
                            $bot = $primaryBot;
                            $botResolutionSource = 'channel_primary_bot';
                        } else {
                            // Priority 4: Multiple bots, no primary — DO NOT make arbitrary choice
                            $botResolutionSource = 'channel_multiple_bots_no_primary';
                            Log::error('ProcessAutoReply: multiple active bots assigned to channel without explicit primary — no bot selected', [
                                'channel_id' => $channel->id,
                                'business_id' => $business->id,
                                'all_bot_ids' => $activeBots->pluck('id')->toArray(),
                                'all_bot_names' => $activeBots->pluck('name')->toArray(),
                                'conversation_id' => $conversation->id,
                            ]);
                        }
                    } else {
                        // Priority 5: No bot assigned
                        $botResolutionSource = 'no_bot_assigned';
                    }
                }

                // Persist bot snapshot on conversation (only for new assignments)
                if ($bot && empty($conversation->bot_id)) {
                    $conversation->update(['bot_id' => $bot->id]);
                }
            } catch (\Throwable $e) {
                Log::warning('ProcessAutoReply: Bot resolution skipped (legacy fallback)', [
                    'exception_type' => get_class($e),
                    'channel_id' => $channel->id ?? null,
                    'business_id' => $business->id,
                ]);
                $botResolutionSource = 'error_fallback';
                $bot = null;
            }
        }

        // Audit: Bot resolution result
        Log::info('ProcessAutoReply: Bot resolution', [
            'conversation_id' => $conversation->id,
            'channel_id' => $channel->id ?? null,
            'business_id' => $business->id ?? null,
            'resolved_bot_id' => $bot?->id,
            'resolved_bot_name' => $bot?->name,
            'resolution_source' => $botResolutionSource,
            'ecommerce_channel_id' => $bot?->ecommerce_channel_id,
            'ai_provider' => !empty($bot?->ai_provider) ? $bot->ai_provider : ($business?->ai_provider ?? env('AI_PROVIDER', 'groq')),
            'ai_model' => !empty($bot?->ai_model) ? $bot->ai_model : ($business?->ai_model ?? env('AI_MODEL')),
        ]);

        // Apply tone/persona customization — Bot overrides BusinessProfile
        $effectiveToneStyle = !empty($bot?->ai_tone_style) ? $bot->ai_tone_style : ($channel->business?->ai_tone_style ?? [
            'tone' => 'friendly',
            'formality' => 'casual',
            'focus' => 'support'
        ]);
        
        $isProductBrowseMessage = (bool) preg_match(
            '/(?:\b(?:show|see|view|browse|list|display|send)\b.{0,60}\b(?:products?|items?|catalog(?:ue)?|images?|photos?|pictures?)\b|\b(?:products?|items?|catalog(?:ue)?)\b.{0,60}\b(?:images?|photos?|pictures?|show|see|view|browse|list|all)\b|i\s+wanna\s+see\s+products?|(?:عايز|عايزة|ابغى|أبغى|اريد|أريد|وريني|اريني|أرني|اعرض|أعرض|هات).{0,60}(?:المنتجات?|منتجات|المنتج|البضايع))/iu',
            $message->content
        );

        // Resolve the store once before reference or intent handling. References must
        // be validated against the same deterministic conversation commerce context.
        // A fresh product browse should follow the bot's current default, even when a
        // previous turn left stale checkout details on the conversation.
        $commerceContext = app(\App\Services\EcommerceChannelResolver::class)
            ->resolveConversation($conversation, $bot, $isProductBrowseMessage);
        $commerceChannel = $commerceContext['connection'];
        $sallaChannel = $commerceChannel?->type === 'salla' ? $commerceChannel : null;

        $referencedProductResolver = app(\App\Services\ReferencedProductResolver::class);
        $referenceResolution = $referencedProductResolver->resolve($message, $conversation, $commerceChannel);
        $referencedProduct = $referenceResolution['product'];
        $quotedMessageId = $referenceResolution['referenced_platform_message_id'];
        $referenceIntent = $referencedProductResolver->intentFor($message->content, $referenceResolution);

        if ($referenceIntent === 'place_order' && array_key_exists('available', $referencedProduct ?? []) && !$referencedProduct['available']) {
            $replyMessage = Message::create([
                'conversation_id' => $conversation->id,
                'content' => $detectedLanguage === 'arabic'
                    ? 'المنتج ' . ($referencedProduct['name'] ?? '') . ' سعره ' . ($referencedProduct['price'] ?? '') . ' ' . ($referencedProduct['currency'] ?? 'SAR') . '، لكنه غير متوفر حاليًا، لذلك لم أبدأ الطلب. تحب أعرض لك بدائل متوفرة؟'
                    : ($referencedProduct['name'] ?? 'This item') . ' is listed at ' . ($referencedProduct['price'] ?? '') . ' ' . ($referencedProduct['currency'] ?? 'SAR') . ', but it is currently out of stock, so I did not start an order. Would you like available alternatives?',
                'direction' => 'outbound',
                'status' => 'auto',
                'is_ai' => false,
                'source' => 'catalog_unavailable',
                'send_status' => 'pending',
            ]);
            if ($channel->user_id) {
                broadcast(new \App\Events\MessageReceived($replyMessage, $conversation, $channel->user_id));
            }
            Log::info('ProcessAutoReply: blocked order request for unavailable catalog product', [
                'conversation_id' => $conversation->id,
                'commerce_channel_id' => $commerceChannel?->id,
                'product_id' => $referencedProduct['commerce_external_id'] ?? null,
            ]);
            $this->sendReply($channel, $conversation, $replyMessage);
            return;
        }

        // A referenced product is evidence about identity, not permission to
        // place an order. Ordering language is required; pointer-only messages
        // remain product questions and still pass through the order summary flow.
        // Stored checkout values are sanitized on load so a placeholder or platform ID saved by an earlier turn can never reach the summary or the order payload.
        $checkoutState = is_array($conversation->checkout_state)
            ? \App\Services\OrderCheckoutService::sanitizeStoredPhone($conversation->checkout_state)
            : null;
        // Keep the checkout's language for replies such as "1" or "2", which
        // contain no language signal of their own.
        if (!empty($checkoutState['checkout_language'])
            && OrderCheckoutService::isExplicitOrderConfirmation((string) $message->content)) {
            $detectedLanguage = $checkoutState['checkout_language'] === 'arabic' ? 'arabic' : 'english';
        } elseif (!empty($checkoutState['checkout_language'])
            && !preg_match('/[\p{L}]/u', (string) $message->content)) {
            $detectedLanguage = $checkoutState['checkout_language'] === 'arabic' ? 'arabic' : 'english';
        }

        // Handle explicit checkout cancellation before product recovery, AI
        // classification, or any provider order API can run. This transition is
        // deterministic so an AI guess cannot turn "cancel" into confirmation.
        if ($this->handleExplicitCheckoutCancellation($channel, $conversation, $message, $checkoutState)) {
            return;
        }

        // A browse request starts a product inquiry, even if it quotes an old
        // product image or a stale checkout_state still contains a product.
        if ($isProductBrowseMessage) {
            $referencedProduct = null;
            $checkoutState = null;
            $conversation->update(['checkout_state' => null]);
        }

        if (!$isProductBrowseMessage && !$referencedProduct && !empty($checkoutState['salla_product_id'])) {
            $referencedProduct = [
                'salla_product_id' => $checkoutState['salla_product_id'],
                'sku'              => $checkoutState['sku']              ?? null,
                'name'             => $checkoutState['product_name']     ?? 'Unknown',
                'price'            => $checkoutState['product_price']    ?? '?',
                'currency'         => $checkoutState['product_currency'] ?? 'SAR',
                'image'            => null,
            ];
            Log::info('ProcessAutoReply: restored referenced product from checkout_state (mid-order turn)', [
                'conversation_id' => $conversation->id,
                'product_id'      => $referencedProduct['salla_product_id'],
                'checkout_fields' => array_keys(array_filter($checkoutState)),
            ]);
        }

        // ── SMART SALLA FLOW ──────────────────────────────────────────────────
        // Detect intent from message BEFORE calling AI, so we can pre-load the
        // right Salla data (order data OR product list) into the AI context.
        $sallaContext   = null;
        $sallaOrderReferenceMatchedUnverified = false;
        $matchedSallaOrderReference = null;
        $pendingSallaStatusReference = trim((string) ($checkoutState['pending_salla_status_reference_id'] ?? ''));
        $pendingStatusText = strtr((string) $message->content, [
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
            '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
        ]);
        $pendingSallaStatusPhoneReply = $pendingSallaStatusReference !== ''
            && (bool) preg_match('/(?:^\s*\+?[0-9][0-9\s().-]{7,17}\s*$|(?:phone|mobile|telephone|رقم\s*(?:الجوال|الهاتف))\D{0,20}\+?[0-9][0-9\s().-]{7,17}[0-9])/iu', $pendingStatusText);
        $productsContext = null;
        $msgLower = mb_strtolower($message->content);

        // Keywords that indicate customer is asking about an EXISTING order
        $orderStatusKeywords = [
            'order status', 'status of my order', 'track my order', 'track order',
            'where is my order', 'where is the order', 'when will my order',
            'was my order placed', 'was the order submitted', 'did my order go through', 'is my order confirmed', 'verify my order', 'check my order',
            'حالة الطلب', 'حالة طلبي', 'تتبع الطلب', 'تتبع طلبي', 'وين طلبي', 'فين طلبي',
            'أين طلبي', 'اين طلبي', 'متى يوصل طلبي', 'متى يصل الطلب', 'هل تم إرسال طلبي', 'هل تم ارسال طلبي', 'هل تأكد طلبي', 'هل تأكد الطلب',
        ];
        // Keywords that indicate customer wants to PLACE a new order / buy a SPECIFIC item.
        // ⚠️  Do NOT add general product-browse phrases here ("show me products", "what do you
        // sell", "المنتجات") — those belong to the aggregate detection block above and
        // adding them here causes "fetch the products" to be misclassified as place_order,
        // which triggers an unwarranted escalation.
        $placeOrderKeywords = [
            'place an order','place the order','i want to order','i want to buy','purchase','i\'d like to order', 'i would like to order', 'would like to order',
            'i\'d like to buy','i\'ll take','i will take','add to cart','i wanna order','i wanna buy',
            'i wanna place','okay now i wanna','now i wanna',
            'عايز اطلب','عاوز اطلب','ابي اشتري','ابي اطلب','ابغا اشتري','ابغى اطلب','أبغى أطلب','ودي اطلب','ودي أطلب','اشتري','اطلب منكم',
        ];

        // ── AGGREGATE INTENT DETECTION ────────────────────────────────────────
        // Strategy: regex patterns are far more robust than keyword lists for real-world
        // phrasing variation. These patterns are designed to be broad but precise —
        // they match the INTENT (browse/count products or orders) not specific words.
        //
        // Patterns explained:
        //   \bproduct\w* — matches "product", "products", "products?"
        //   \border\w*   — matches "order", "orders"
        //   The word-boundary anchors (\b) prevent "unordered" matching "order".
        //
        // English product browse/count patterns
        $productAggregatePattern = '/(' .
            'how many (products?|items?|things?|stuff)' .
            '|what (products?|items?|things?).*(have|sell|got|offer|carry|available)' .
            '|(products?|items?).*(you have|you sell|you got|you offer|in your store|on salla|available)' .
            '|(show|list|fetch|get|give|display|send).*(me |us |your |all )?(the )?(products?|items?|catalogue|catalog|inventory|what you.*(have|sell|got))' .
            '|(products?|items?).*(show|list|fetch|get|see|view|browse|check)' .
            '|your (products?|items?|inventory|catalogue|catalog)' .
            '|what do you sell' .
            '|what.*(sell|have|offer|carry)' .
            '|do you have (any )?(products?|items?|stuff|things?)' .
            ')/iu';

        // Arabic product browse/count patterns
        $productAggregatePatternAr = '/(' .
            'كم (منتج|عدد المنتجات|صنف|منتجات)' .
            '|عدد المنتجات|قائمة المنتجات|كل المنتجات' .
            '|(اعرض|وريني|اريني|أرني|شوفني|أعرض|ارسل|ابعث).*(المنتجات?|المنتج|البضايع|اللي عندك)' .
            '|ما (عندكم|لديكم|عندك|لديك|هي المنتجات)' .
            '|ايش (عندكم?|لديكم?|تبيعون?|تبيعوا)' .
            '|إيش (عندكم?|لديكم?|تبيعون?|تبيعوا)' .
            '|شو (عندكم?|لديكم?|تبيعون?|بتبيعوا)' .
            '|المنتجات' .
        ')/iu';

        // English order list/count patterns
        $orderAggregatePattern = '/(' .
            'how many orders?' .
            '|orders?.*(exist|are there|do you have|in.*(store|system|salla))' .
            '|(show|list|fetch|get|display|give me).*(me |us |my |all )?(the )?orders?' .
            '|all (my |the )?orders?' .
            '|my orders?' .
            ')/iu';

        // Arabic order list/count patterns
        $orderAggregatePatternAr = '/(' .
            'كم (طلب|طلبات?|عدد الطلبات)' .
            '|عدد الطلبات|قائمة الطلبات|كل الطلبات' .
            '|(اعرض|وريني|اريني|أرني|شوفني).*(طلباتي?|الطلبات?)' .
            '|طلباتي' .
        ')/iu';

        $isProductAggregate = $isProductBrowseMessage
            || (bool) preg_match($productAggregatePattern, $message->content)
            || (bool) preg_match($productAggregatePatternAr, $message->content)
            || (bool) preg_match('/(?:\x{0627}\x{0639}\x{0631}\x{0636}|\x{0634}\x{0648}\x{0641}|\x{0648}\x{0631}\x{064A}\x{0646}\x{064A}|\x{0627}\x{0631}\x{0633}\x{0644}|\x{0627}\x{0628}\x{0639}\x{062B}).{0,40}(?:\x{0627}\x{0644}\x{0645}\x{0646}\x{062A}\x{062C}\x{0627}\x{062A}?|\x{0627}\x{0644}\x{0628}\x{0636}\x{0627}\x{064A}\x{0639})/u', $message->content);

        // Aggregate detection also recognizes natural and Arabic browse
        // phrases that the narrower English phrase matcher cannot cover.
        // Treat those as browse intent before checkout state or escalation
        // can interpret a stale product as an order confirmation.
        if ($isProductAggregate) {
            $isProductBrowseMessage = true;
            $referencedProduct = null;
            $checkoutState = null;
            $conversation->update(['checkout_state' => null]);
        }

        // The broad singular `my order` pattern also matched status questions
        // such as "what is my order status?" and sent them down the store-wide
        // order-list path. Explicit status/tracking requests always win.
        $isExplicitOrderStatusRequest = (bool) preg_match(
            '/\b(?:order\s+)?status\b|\bwhere\s+(?:is|are)\s+(?:my|the)\s+orders?\b|\bwhen\s+will\s+(?:my|the)\s+order\b|\btrack(?:ing)?\s+(?:my\s+|the\s+)?orders?\b|\b(?:was\s+(?:my|the)\s+order\s+(?:placed|submitted)|did\s+my\s+order\s+go\s+through|is\s+my\s+order\s+confirmed|(?:verify|check)\s+my\s+order)\b|(?:حالة\s*(?:الطلب|طلبي)|وين\s*(?:طلبي|الطلب)|فين\s*(?:طلبي|الطلب)|أين\s*(?:طلبي|الطلب)|اين\s*(?:طلبي|الطلب)|متى\s*(?:يوصل|يصل)\s*(?:طلبي|الطلب)|تتبع\s*(?:طلبي|الطلب)|هل\s*(?:تم\s*(?:إرسال|ارسال|تأكيد|تاكيد)|تأكد)\s*(?:طلبي|الطلب))/iu',
            $message->content
        );
        $isOrderAggregate = !$isExplicitOrderStatusRequest && (
            (bool) preg_match($orderAggregatePattern, $message->content)
            || (bool) preg_match($orderAggregatePatternAr, $message->content)
        );

        // ── FOLLOW-UP CONTEXT DETECTION ───────────────────────────────────────
        if (!$isProductAggregate && !$isOrderAggregate) {
            $affirmativeFollowUp = preg_match(
                '/(yeah|yes|yep|sure|ok|okay|go ahead|please|fetch|show|bring|send|get|see|view|exactly|right|correct)/i',
                $message->content
            );
            $imageRequest = preg_match(
                '/(images?|photos?|pictures?|pics?|صور|صورة|صوره)/i',
                $message->content
            );

            if ($affirmativeFollowUp || $imageRequest) {
                // Check last AI message for context clues
                $lastAiContent = Message::where('conversation_id', $conversation->id)
                    ->where('is_ai', true)
                    ->where('direction', 'outbound')
                    ->orderBy('created_at', 'desc')
                    ->value('content');

                if ($lastAiContent) {
                    $lastAiLower = mb_strtolower($lastAiContent);
                    // Last AI message talked about products
                    $aiMentionedProducts = (str_contains($lastAiLower, 'product') || str_contains($lastAiLower, 'منتج') || str_contains($lastAiLower, 'item'));
                    $aiAskedRephrase = (str_contains($lastAiLower, 'rephrase') || str_contains($lastAiLower, 'could you') || str_contains($lastAiLower, 'please') || str_contains($lastAiLower, 'how many'));

                    if ($imageRequest && $aiMentionedProducts) {
                        $isProductAggregate = true;
                        Log::info('ProcessAutoReply: detected product image request follow-up', [
                            'conversation_id' => $conversation->id,
                        ]);
                    } elseif ($affirmativeFollowUp && $aiMentionedProducts && $aiAskedRephrase) {
                        $isProductAggregate = true;
                        Log::info('ProcessAutoReply: detected product-aggregate follow-up after AI asked for rephrase', [
                            'conversation_id' => $conversation->id,
                            'user_message'    => $message->content,
                            'last_ai_message' => substr($lastAiContent, 0, 100),
                        ]);
                    }

                    // Last AI message talked about orders
                    if ((str_contains($lastAiLower, 'order') || str_contains($lastAiLower, 'طلب'))
                        && $aiAskedRephrase && $affirmativeFollowUp) {
                        $isOrderAggregate = true;
                    }
                }
            }
        }

        $isOrderStatus = false;
        $isPlaceOrder  = false;

        foreach ($orderStatusKeywords as $kw) {
            if (str_contains($msgLower, $kw)) { $isOrderStatus = true; break; }
        }
        foreach ($placeOrderKeywords as $kw) {
            if (str_contains($msgLower, $kw)) { $isPlaceOrder = true; break; }
        }
        if ($pendingSallaStatusPhoneReply) {
            // The previous turn explicitly asked for a phone to verify one
            // order number. Route the phone back to that exact lookup before
            // an active checkout can consume it as a checkout field.
            $isOrderStatus = true;
            $isPlaceOrder = false;
        }

        // Customer details during an active checkout are checkout input, even
        // when they contain a phone number, delivery, or the word "order".
        // A real status question is recognized by its status/tracking wording.
        $incomingCheckoutDetails = (bool) preg_match(
            '/\b(?:full\s+name|name|phone|mobile|address|delivery\s+address|city|building|street|postal|zip|cash\s+on\s+delivery|cod)\b|رقم\s*(?:الجوال|الهاتف)|(?:الاسم|اسمي|المدينة|مدينة\s*التوصيل|العنوان|عنوان\s*التوصيل|رقم\s*(?:المبنى|الشارع)|الدفع\s*عند\s*الاستلام)/iu',
            (string) $message->content
        );
        if (!empty($checkoutState['salla_product_id']) && $incomingCheckoutDetails && !$isExplicitOrderStatusRequest) {
            $isOrderStatus = false;
            $isPlaceOrder = true;
        }

        // An exact customer confirmation outranks the generic `order` status
        // keyword match (e.g. "confirm order" contains both intents).
        $isExplicitOrderConfirmationMessage = OrderCheckoutService::isExplicitOrderConfirmation((string) $message->content);
        if ($isExplicitOrderConfirmationMessage) {
            $isOrderStatus = false;
            $isPlaceOrder = true;
            $isOrderAggregate = false;
            $isProductAggregate = false;
        }
        $isExplicitOrderStatusMessage = $isOrderStatus && !$isOrderAggregate && !$isProductAggregate;

        // Aggregate intent is more specific than the generic order-status / place-order
        // keyword buckets (which share words like "order" and "products") — it always wins.
        if ($isProductAggregate || $isOrderAggregate) {
            $isOrderStatus = false;
            $isPlaceOrder  = false;
        }

        // Capture intent before applying the explicit reference policy for logs.
        $intentBefore = $isOrderStatus ? 'order_status' : ($isPlaceOrder ? 'place_order' : ($isProductAggregate ? 'product_inquiry' : 'question'));
        if ($sallaChannel) {
            Log::info('ProcessAutoReply: Salla intent route selected', [
                'conversation_id' => $conversation->id,
                'message_id' => $message->id,
                'route' => $isOrderStatus ? 'status_lookup' : ($isExplicitOrderConfirmationMessage ? 'order_confirmation' : ($isPlaceOrder ? 'checkout' : 'general_ai')),
            ]);
        }

        // A resolved pointer becomes an order only when ordering language is
        // present. A pointer alone asks about that product.
        if ($referenceIntent === 'place_order') {
            $isPlaceOrder = true;
            $isProductAggregate = false;
            $isOrderAggregate = false;
            $isOrderStatus = false;
        } elseif ($referenceIntent === 'question' && !$isPlaceOrder) {
            $isPlaceOrder = false;
        }

        // A product reference may provide context, but it must not overwrite
        // an explicit status question or a confirmation of the held checkout.
        if ($isExplicitOrderConfirmationMessage) {
            $isOrderStatus = false;
            $isPlaceOrder = true;
        } elseif ($isExplicitOrderStatusMessage) {
            $isOrderStatus = true;
            $isPlaceOrder = false;
            $isProductAggregate = false;
            $isOrderAggregate = false;
        }

        // A phone sent in response to the verification prompt belongs to the
        // pending order lookup, even if an old checkout is still held.
        if ($sallaChannel && $pendingSallaStatusPhoneReply) {
            $isOrderStatus = true;
            $isPlaceOrder = false;
            $isProductAggregate = false;
            $isOrderAggregate = false;
        }

        if ($referenceResolution['is_pointer'] || $quotedMessageId) {
            $intentAfter = $referenceIntent ?? ($referenceResolution['resolution'] === 'ambiguous' ? 'clarify_product' : $intentBefore);
            Log::info('Reference resolution', [
                'message_id' => $message->id,
                'conversation_id' => $conversation->id,
                'channel_type' => $channel->type,
                'referenced_platform_message_id_present' => !empty($quotedMessageId),
                'match_method' => $referenceResolution['match_method'],
                'platform_id_normalized' => $referenceResolution['platform_id_normalized'] ?? false,
                'resolution' => $referenceResolution['resolution'],
                'product_external_id' => $referencedProduct['commerce_external_id'] ?? null,
                'store_id' => $commerceChannel?->id,
                'intent_before' => $intentBefore,
                'intent_after' => $intentAfter,
            ]);
        }

        // Bug 6 fix: detect bare order-number follow-up replies like "#276817444" or "276817444".
        // If the customer's message is *just* an order number (digits, possibly prefixed with #),
        // AND a recent AI message asked for the order number, treat this as an order-status request.
        if (!$isOrderStatus) {
            $trimmedMsg = trim($message->content);
            $isBareSaudiPhone = preg_match('/^\+?[0-9\s().-]+$/u', $trimmedMsg)
                && \App\Helpers\SallaPhoneHelper::getCountryCode($trimmedMsg) === 'SA'
                && \App\Helpers\SallaPhoneHelper::isValidSaudiMobile($trimmedMsg);
            if (preg_match('/^#?(\d{5,12})$/', $trimmedMsg, $bareMatch)) {
                // Looks like a standalone order number. Check if last AI reply asked for it.
                $recentAiMsg = Message::where('conversation_id', $conversation->id)
                    ->where('is_ai', true)
                    ->where('direction', 'outbound')
                    ->orderBy('created_at', 'desc')
                    ->value('content');

                $orderRequestPhrases = ['order number', 'رقم الطلب', 'رقم طلبك', 'order no', 'your order', 'phone number', 'mobile number', 'رقم الجوال', 'رقم الهاتف'];
                $aiAskedForOrderNumber = false;
                if ($recentAiMsg) {
                    $recentAiLower = mb_strtolower($recentAiMsg);
                    foreach ($orderRequestPhrases as $phrase) {
                        if (str_contains($recentAiLower, $phrase)) {
                            $aiAskedForOrderNumber = true;
                            break;
                        }
                    }
                }

                if ($aiAskedForOrderNumber) {
                    $isOrderStatus = true;
                    Log::info('ProcessAutoReply: detected bare order number follow-up', [
                        'conversation_id' => $conversation->id,
                        'order_number'    => $bareMatch[1],
                    ]);
                }
            } elseif ($isBareSaudiPhone) {
                $recentAiMsg = Message::where('conversation_id', $conversation->id)
                    ->where('is_ai', true)
                    ->where('direction', 'outbound')
                    ->orderBy('created_at', 'desc')
                    ->value('content');
                $recentAiLower = mb_strtolower((string) $recentAiMsg);
                $askedForLookupDetail = false;
                foreach (['order number', 'رقم الطلب', 'رقم طلبك', 'order no', 'your order', 'phone number', 'mobile number', 'رقم الجوال', 'رقم الهاتف'] as $phrase) {
                    if (str_contains($recentAiLower, $phrase)) {
                        $askedForLookupDetail = true;
                        break;
                    }
                }
                if ($askedForLookupDetail) {
                    $isOrderStatus = true;
                    Log::info('ProcessAutoReply: detected Saudi phone follow-up for order lookup', [
                        'conversation_id' => $conversation->id,
                    ]);
                }
            }
        }

        // Find Salla channel — Priority 1: Bot's explicit ecommerce_channel_id if set
        // Priority 2: Conversation's own channel if it IS a Salla channel
        // Priority 3: Fall back to resolveUniqueConnectedChannel
        $noAgentFeatureEnabled = \App\Models\FeatureFlag::isEnabled('phase3_no_agent_fail_closed', (int) $business->id);
        if ($noAgentFeatureEnabled && ($invalidStickyBot || !$bot)) {
            $reason = $invalidStickyBot ? 'conversation_agent_inactive_or_invalid' : $botResolutionSource;
            $conversation->update([
                'requires_human' => true,
                'ai_enabled' => false,
                'status' => 'pending_human',
                'escalated_at' => now(),
                'escalation_reason' => $reason,
            ]);
            app(\App\Services\HandoffTaskService::class)->openForConversation($conversation, $reason);
            Log::warning('ProcessAutoReply: no eligible agent resolved; stopped automatic reply and queued human attention', [
                'conversation_id' => $conversation->id,
                'channel_id' => $channel->id,
                'business_id' => $business->id,
                'resolution_source' => $botResolutionSource,
                'invalid_sticky_bot' => $invalidStickyBot,
            ]);
            return;
        }

        // Product context must follow the same resolved store as commerce
        // tools. If connected stores exist but routing is unresolved, expose
        // no catalog rather than mixing products across stores.
        if ($business) {
            $productService = new ProductAwarenessService();
            if ($commerceChannel) {
                $productsContext = $productService->buildProductContextArray($business->id, (int) $commerceChannel->id);
            } else {
                $hasConnectedCommerceStore = Channel::query()
                    ->where('business_id', $business->id)
                    ->whereIn('type', ['salla', 'shopify', 'woocommerce'])
                    ->where('status', 'connected')
                    ->exists();
                $productsContext = $hasConnectedCommerceStore
                    ? []
                    : $productService->buildProductContextArray($business->id);
            }
        }

        if (
            !empty($referencedProduct['commerce_channel_id'])
            && (int) $referencedProduct['commerce_channel_id'] !== (int) ($commerceChannel?->id ?? 0)
        ) {
            Log::warning('ProcessAutoReply: ignored referenced product from a different commerce connection', [
                'conversation_id' => $conversation->id,
                'product_connection_id' => $referencedProduct['commerce_channel_id'],
                'resolved_connection_id' => $commerceChannel?->id,
            ]);
            $referencedProduct = null;
        }

        // ── SALLA EXCLUSIVE MODE DETECTION ────────────────────────────────────
        // Restrict this mode to the current turn's commerce intent. Inferring it
        // from an earlier assistant reply mentioning products/orders suppresses
        // the business profile and knowledge base on later general questions.
        $isSallaFlow = false;
        if ($sallaChannel) {
            if ($isProductAggregate || $isOrderAggregate || $isOrderStatus || $isPlaceOrder || ($imageRequest ?? false)) {
                $isSallaFlow = true;
            }
        }

        $productsAggregateContext = null;
        $ordersAggregateContext   = null;

        // Shopify and WooCommerce catalogs are synced into the local product
        // table. Feed the selected store's products into the same aggregate
        // and image-send path used by Salla, so browsing requests do not fall
        // back to an empty list just because the store is not Salla.
        if (!$sallaChannel && $commerceChannel && $isProductAggregate) {
            $commerceProducts = \App\Models\Product::where('business_id', $commerceChannel->business_id)
                ->where('commerce_channel_id', $commerceChannel->id)
                ->where('is_active', true)
                ->orderBy('name')->limit(10)->get();
            $productsAggregateContext = [
                'total_count' => \App\Models\Product::where('commerce_channel_id', $commerceChannel->id)->where('is_active', true)->count(),
                'items' => $commerceProducts->map(function ($product) use ($commerceChannel) {
                    $metadata = $product->metadata ?? [];
                    return [
                        'id' => (string) $product->commerce_external_id,
                        'name' => $product->name,
                        'price' => empty($metadata['variants']) ? (float) $product->price : null,
                        'currency' => $metadata['currency'] ?? 'SAR',
                        'quantity' => (int) $product->stock_quantity,
                        'sku' => $product->sku,
                        'image_url' => $metadata['image_url'] ?? null,
                        'url' => $metadata['url'] ?? null,
                        'commerce_channel_id' => $commerceChannel->id,
                        'commerce_external_id' => (string) $product->commerce_external_id,
                    ];
                })->values()->all(),
            ];
            $productsContext = array_map(fn ($item) => [
                'id' => $item['id'], 'name' => $item['name'], 'price' => $item['price'],
                'currency' => $item['currency'], 'available' => $item['quantity'] > 0,
                'url' => $item['url'], 'image_url' => $item['image_url'],
            ], $productsAggregateContext['items']);
            Log::info('ProcessAutoReply: commerce products aggregate loaded from synced catalog', [
                'channel_id' => $commerceChannel->id,
                'provider' => $commerceChannel->type,
                'total_count' => $productsAggregateContext['total_count'],
            ]);
        }

        // Shopify and WooCommerce order lookups are scoped to the same
        // connection as the product catalog for this conversation.
        if ($isOrderStatus && $commerceChannel && in_array($commerceChannel->type, ['shopify', 'woocommerce'], true)) {
            $phone = $this->senderPhoneDigits($conversation);
            try {
                if ($commerceChannel->type === 'shopify' && $phone !== '') {
                    $response = \App\Services\ShopifyHttp::client($commerceChannel->access_token)
                        ->get("https://{$commerceChannel->page_id}/admin/api/" . config('services.shopify.api_version', '2026-07') . "/orders.json", [
                            'status' => 'any',
                            'limit' => 1,
                            'order' => 'created_at desc',
                            'fields' => 'id,name,order_number,financial_status,fulfillment_status,total_price,currency,created_at,line_items',
                            'query' => $phone,
                        ]);
                    $order = $response->successful() ? ($response->json()['orders'][0] ?? null) : null;
                    if ($order) {
                        $sallaContext = $this->formatShopifyOrderForAI($order);
                    }
                } elseif ($commerceChannel->type === 'woocommerce' && $phone !== '') {
                    $order = (new \App\Services\WooCommerceService())
                        ->getOrderByPhone($commerceChannel->metadata ?? [], $phone);
                    if ($order) {
                        $sallaContext = (new \App\Services\WooCommerceService())->formatOrderForAI($order);
                    }
                }
            } catch (\Throwable $e) {
                Log::warning('ProcessAutoReply: scoped commerce order lookup failed', [
                    'conversation_id' => $conversation->id,
                    'connection_id' => $commerceChannel->id,
                    'provider' => $commerceChannel->type,
                    'exception_type' => get_class($e),
                ]);
            }
        }

        if ($sallaChannel) {

            // ── STORE AGGREGATE FLOW (Priority 1 fix) ────────────────────────
            // Aggregate/list queries must hit the real Salla list endpoints
            // (GET /products, GET /orders) — never the single-resource endpoints,
            // and never silently fall back to "no access". If the call fails we still
            // pass a structured error into the AI context so it can be honest about it
            // instead of hallucinating a generic "I don't have access" line.
            if ($isProductAggregate) {
                try {
                    $sallaService = new SallaService();
                    $raw = $sallaService->getProductsForChannel($sallaChannel, ['per_page' => 10]);
                    $productsAggregateContext = $sallaService->formatProductsListForAI($raw);
                    Log::info('ProcessAutoReply: Salla products aggregate fetched', [
                        'total_count' => $productsAggregateContext['total_count'] ?? null,
                    ]);
                } catch (\Exception $e) {
                    Log::error('ProcessAutoReply: Salla products aggregate fetch failed', ['exception_type' => get_class($e)]);
                    $productsAggregateContext = ['error' => $e->getMessage()];
                }
            }

            // Store-wide order lists are never sent to the model. A status question
            // must be answered only from the customer's own order (verified by phone or
            // order number below). Feeding the store's latest order here made the bot
            // present another customer's order as the sender's.

            // ── ORDER STATUS FLOW ────────────────────────────────────────────
            if ($isOrderStatus) {
                $rawPhone = $this->senderPhoneDigits($conversation);

                // WhatsApp: try direct order-number lookup first (if message is a bare order number),
                // then fall back to phone-based lookup.
                if ($channel->type === 'whatsapp' && $rawPhone) {
                    try {
                        $sallaService = new SallaService();

                        // If message is a bare order number, look up directly
                        $trimmedMsg = trim($message->content);
                        if (preg_match('/(?:\border\b|طلب)?\s*(?:number|no\.?|رقم)?\s*[:#-]?\s*#?(\d{5,12})/iu', $trimmedMsg, $bareOrderMatch)) {
                            $order = $sallaService->getOrderByReferenceForChannel($sallaChannel, $bareOrderMatch[1]);
                            if ($order && !$this->orderBelongsToSender($order, [$rawPhone])) {
                                Log::warning('ProcessAutoReply: order number does not belong to sender; not disclosed');
                                $order = null;
                            }
                            if ($order) {
                                $sallaContext = $sallaService->formatOrderForAI($order);
                                Log::info('ProcessAutoReply: Salla order found by order number (WhatsApp follow-up)', [
                                    'order_number' => $bareOrderMatch[1],
                                    'order_id'     => $order['id'] ?? null,
                                ]);
                            }
                        }

                        // Fall back to phone lookup if order-number lookup didn't yield results
                        if (!$sallaContext) {
                            $order = $sallaService->getLatestOrderByPhoneForChannel($sallaChannel, $rawPhone);
                            if ($order) {
                                $sallaContext = $sallaService->formatOrderForAI($order);
                                Log::info('ProcessAutoReply: Salla order found for WhatsApp sender', [
                                    'has_sender_phone' => $rawPhone !== '',
                                    'order_id' => $order['id'] ?? null,
                                ]);
                            }
                        }
                        // If no order found, sallaContext stays null → AI will ask for order number
                    } catch (\Exception $e) {
                        Log::warning('ProcessAutoReply: Salla order lookup failed', ['exception_type' => get_class($e)]);
                    }
                } else {
                    // Use lookup details only when the customer supplied them
                    // with this status request. A held checkout's old address,
                    // postal code, or phone must not silently become a lookup.
                    $lookupText = (string) $message->content;
                    $orderNumberMatch = null;
                    $phoneMatch       = null;
                    if (preg_match('/(?:\borders?\s*(?:(?:number|no\.?|id)\s*)?(?:(?:is|:|#|-)\s*)?#?\s*(\d{4,12})\b|\bرقم\s*الطلب\s*(?:(?:هو|:|#|-)\s*)?#?\s*(\d{4,12})\b)/iu', $lookupText, $mo)) {
                        $orderNumberMatch = $mo[1] ?? $mo[2] ?? null;
                    } elseif (preg_match('/^\s*#?(\d{5,12})\s*$/u', $lookupText, $mo)) {
                        $orderNumberMatch = $mo[1];
                    }
                    if ($pendingSallaStatusPhoneReply && $pendingSallaStatusReference !== '') {
                        $orderNumberMatch = $pendingSallaStatusReference;
                    }
                    $matchedSallaOrderReference = $orderNumberMatch;
                    if (preg_match('/(?:phone|mobile|telephone|رقم\s*(?:الجوال|الهاتف))\D{0,12}(\+?\d[\d\s().-]{7,17}\d)/iu', $lookupText, $mp)) {
                        $phoneCandidate = trim($mp[1]);
                        if (\App\Helpers\SallaPhoneHelper::getCountryCode($phoneCandidate) === 'SA'
                            && \App\Helpers\SallaPhoneHelper::isValidSaudiMobile($phoneCandidate)) {
                            $phoneMatch = \App\Helpers\SallaPhoneHelper::formatForCustomerMobile($phoneCandidate);
                        }
                    } else {
                        $phoneCandidates = [];
                        if (preg_match('/^\+?[0-9][0-9\s().-]{7,17}$/u', trim($lookupText))) {
                            $phoneCandidates[] = trim($lookupText);
                        } elseif (preg_match_all('/(?<![0-9])\+?[0-9][0-9\s().-]{7,17}[0-9](?![0-9])/u', $lookupText, $phoneMatches)) {
                            $phoneCandidates = $phoneMatches[0];
                        }
                        foreach ($phoneCandidates as $phoneCandidate) {
                            if (\App\Helpers\SallaPhoneHelper::getCountryCode($phoneCandidate) === 'SA'
                                && \App\Helpers\SallaPhoneHelper::isValidSaudiMobile($phoneCandidate)) {
                                $phoneMatch = \App\Helpers\SallaPhoneHelper::formatForCustomerMobile($phoneCandidate);
                                break;
                            }
                        }
                    }

                    if ($orderNumberMatch || $phoneMatch) {
                        try {
                            $sallaService = new SallaService();
                            $order = null;
                            if ($orderNumberMatch) {
                                $order = $sallaService->getOrderByReferenceForChannel($sallaChannel, $orderNumberMatch);
                            if ($order && !$this->orderBelongsToSender($order, [$phoneMatch, $this->senderPhoneDigits($conversation)])) {
                                    $sallaOrderReferenceMatchedUnverified = $orderNumberMatch !== null && !$phoneMatch;
                                    Log::warning('ProcessAutoReply: order number does not belong to sender; not disclosed');
                                    $order = null;
                                }
                            } elseif ($phoneMatch) {
                                $order = $sallaService->getLatestOrderByPhoneForChannel($sallaChannel, $phoneMatch);
                            }
                            if ($order) {
                                $sallaContext = $sallaService->formatOrderForAI($order);
                            }
                        } catch (\Exception $e) {
                            Log::warning('ProcessAutoReply: Salla order lookup (non-WA) failed', ['exception_type' => get_class($e)]);
                        }

                        // Query only the Shopify account already resolved for
                        // this conversation. Never search a different provider.
                        if (!$sallaContext && $commerceChannel?->type === 'shopify') {
                            try {
                                $shopifyChannel = $commerceChannel;

                                if ($shopifyChannel && $phoneMatch) {
                                    $shopifyResponse = \App\Services\ShopifyHttp::client($shopifyChannel->access_token)
                                        ->get("https://{$shopifyChannel->page_id}/admin/api/" . config('services.shopify.api_version', '2026-07') . "/orders.json", [
                                            'customer_phone' => $phoneMatch,
                                            'status' => 'any',
                                            'limit' => 1,
                                            'sort_by' => 'created_at',
                                            'sort_order' => 'desc',
                                        ]);
                                    
                                    if ($shopifyResponse->successful() && !empty($shopifyResponse->json()['orders'])) {
                                        $shopifyOrder = $shopifyResponse->json()['orders'][0];
                                        $sallaContext = $this->formatShopifyOrderForAI($shopifyOrder);
                                        Log::info('ProcessAutoReply: Shopify order lookup succeeded');
                                    }
                                }
                            } catch (\Exception $e) {
                                Log::warning('ProcessAutoReply: Shopify order lookup failed', ['exception_type' => get_class($e)]);
                            }
                        }

                        // Query only the resolved WooCommerce account.
                        if (!$sallaContext && $commerceChannel?->type === 'woocommerce') {
                            try {
                                $wooCommerceChannel = $commerceChannel;

                                if ($wooCommerceChannel && $phoneMatch) {
                                    $wooCommerceService = new \App\Services\WooCommerceService();
                                    $wooOrder = $wooCommerceService->getOrderByPhone($wooCommerceChannel->metadata, $phoneMatch);
                                    
                                    if ($wooOrder) {
                                        $sallaContext = $wooCommerceService->formatOrderForAI($wooOrder);
                                        Log::info('ProcessAutoReply: WooCommerce order lookup succeeded');
                                    }
                                }
                            } catch (\Exception $e) {
                                Log::warning('ProcessAutoReply: WooCommerce order lookup failed', ['exception_type' => get_class($e)]);
                            }
                        }
                    }
                }
            }

            // ── PLACE ORDER FLOW ─────────────────────────────────────────────
            if ($isPlaceOrder) {
                if ($referencedProduct) {
                    // CRITICAL ISSUE — REPLY-TO-PRODUCT CONTEXT: the customer
                    // replied to a specific product image, so the product is
                    // already known deterministically. NEVER re-fetch a generic
                    // product list here and let the AI guess/re-search — that
                    // is exactly the bug this fix closes. Feed the AI a
                    // single-item list containing only the referenced product.
                    $productsContext = [[
                        'id'        => $referencedProduct['salla_product_id'],
                        'name'      => $referencedProduct['name'] ?? 'Unknown',
                        'price'     => (empty($referencedProduct['variants'] ?? []) && empty($referencedProduct['options'] ?? []))
                            ? ($referencedProduct['price'] ?? '?')
                            : null,
                        'currency'  => $referencedProduct['currency'] ?? 'SAR',
                        'available' => true,
                        'url'       => null,
                    ]];

                    Log::info('ProcessAutoReply: place_order using deterministically-resolved referenced product (skipping product re-fetch)', [
                        'conversation_id' => $conversation->id,
                        'product_id'      => $referencedProduct['salla_product_id'],
                    ]);
                } else {
                    // Reuse the aggregate data already fetched this turn if available
                    // (avoids a redundant API round-trip and works even if isProductAggregate
                    // wasn't set, e.g. the customer typed "I want to order" without going
                    // through the browse flow first).
                    if (!empty($productsAggregateContext['items'])) {
                        $productsContext = array_map(function ($item) {
                            return [
                                'id'        => $item['id']        ?? null,
                                'name'      => $item['name']      ?? 'Unknown',
                                'price'     => $item['price']     ?? '?',
                                'currency'  => $item['currency']  ?? 'SAR',
                                'available' => ($item['quantity'] ?? 1) > 0,
                                'url'       => null,
                            ];
                        }, $productsAggregateContext['items']);

                        Log::info('ProcessAutoReply: place_order reusing already-fetched aggregate products', [
                            'conversation_id' => $conversation->id,
                            'count'           => count($productsContext),
                        ]);
                    } else {
                        // No aggregate data yet — fetch fresh using the channel-aware
                        // method (handles token expiry + auto-refresh automatically).
                        // The old getProducts($token) path had no refresh logic and would
                        // silently fail on an expired token, leaving productsContext empty
                        // which caused the AI to escalate with "business_rule".
                        try {
                            $sallaService = new SallaService();
                            $raw          = $sallaService->getProductsForChannel($sallaChannel, ['per_page' => 10]);
                            $formatted    = $sallaService->formatProductsListForAI($raw);

                            $productsContext = array_map(function ($item) {
                                return [
                                    'id'        => $item['id']        ?? null,
                                    'name'      => $item['name']      ?? 'Unknown',
                                    'price'     => $item['price']     ?? '?',
                                    'currency'  => $item['currency']  ?? 'SAR',
                                    'available' => ($item['quantity'] ?? 1) > 0,
                                    'url'       => null,
                                ];
                            }, $formatted['items'] ?? []);

                            Log::info('ProcessAutoReply: Salla products loaded for place_order', [
                                'count' => count($productsContext),
                            ]);
                        } catch (\Exception $e) {
                            Log::warning('ProcessAutoReply: Salla products fetch failed for place_order', ['exception_type' => get_class($e)]);
                        }
                    }
                }
            }
        }
        // ── END SALLA FLOW ────────────────────────────────────────────────────
        
        // Ultimate AI System - Single Call with JSON Output
        $userId = $user->id ?? $conversation->user_id;
        $conversationId = $conversation->id;

        // Step 1: Rate Limiting Check
        $rateLimitCheck = AICapabilitiesService::checkRateLimit($userId, $channel->business?->id ?? 'default');
        if ($rateLimitCheck['rate_limited']) {
            Log::warning('ProcessAutoReply: Rate limit exceeded', [
                'user_id' => $userId,
                'retry_after' => $rateLimitCheck['retry_after']
            ]);

            $rateLimitMessage = "⚠️ You've reached the message limit. Please wait before sending more messages.";
            $replyMessage = Message::create([
                'conversation_id' => $message->conversation_id,
                'content' => $rateLimitMessage,
                'direction' => 'outbound',
                'status' => 'auto',
                'is_ai' => false,
                'source' => 'rate_limit',
                'send_status' => 'pending',
            ]);

            if ($channel->user_id) {
                broadcast(new \App\Events\MessageReceived($replyMessage, $conversation, $channel->user_id));
            }
            $this->sendReply($channel, $conversation, $replyMessage);
            return;
        }

        // Step 2: Hard Escalation Override (Pre-AI Check)
        $hardEscalation = AICapabilitiesService::checkHardEscalation($message->content, (int) $business->id);
        if ($hardEscalation['force_escalation']) {
            Log::info('ProcessAutoReply: Hard escalation override triggered', [
                'conversation_id' => $conversation->id,
                'matched_keyword' => $hardEscalation['matched_keyword']
            ]);

            $conversation->update([
                'requires_human' => true,
                'escalated_at' => now(),
                'escalation_reason' => "hard_keyword_override: {$hardEscalation['matched_keyword']}",
                'ai_enabled' => false,
            ]);

            app(\App\Services\HandoffTaskService::class)->openForConversation(
                $conversation,
                'hard_keyword_override: ' . $hardEscalation['matched_keyword']
            );

            $escalationMessage = "Sure ðŸ‘ I'm connecting you with a human agent now. Please wait a moment.";
            $replyMessage = Message::create([
                'conversation_id' => $message->conversation_id,
                'content' => $escalationMessage,
                'direction' => 'outbound',
                'status' => 'auto',
                'is_ai' => false,
                'source' => 'escalation',
                'send_status' => 'pending',
            ]);

            if ($channel->user_id) {
                broadcast(new \App\Events\MessageReceived($replyMessage, $conversation, $channel->user_id));
            }
            $this->sendReply($channel, $conversation, $replyMessage);
            return;
        }

        // Salla order status is store data, not a language-generation task.
        // The lookup above is already scoped to this conversation's store and
        // checks order ownership; answer directly so provider outages cannot
        // turn a successful lookup into a generic AI failure.
        if ($isOrderStatus && $sallaChannel) {
            $order = $sallaContext ? $this->parseSallaContext($sallaContext) : [];
            $reference = trim((string) ($order['order_number'] ?? ''));
            $status = trim((string) ($order['status'] ?? ''));
            $total = trim((string) ($order['total'] ?? ''));
            $currency = trim((string) ($order['currency'] ?? 'SAR'));
            $shipping = trim((string) ($order['shipping_status'] ?? ''));
            $currentStatusText = trim((string) $message->content);
            $hasIdentifier = (bool) preg_match('/(?:#?\d{5,12})|(?:\+?\d[\d\s().-]{7,17}\d)/u', $currentStatusText);

            if ($order !== []) {
                $statusReply = $detectedLanguage === 'arabic'
                    ? 'لقيت طلبك' . ($reference !== '' ? " رقم #{$reference}" : '') . ($status !== '' ? " وحالته: {$status}" : '')
                        . ($total !== '' ? "، والإجمالي {$total} {$currency}" : '')
                        . ($shipping !== '' && $shipping !== 'Not shipped' ? "، وحالة الشحن: {$shipping}" : '') . '.'
                    : 'I found your order' . ($reference !== '' ? " #{$reference}" : '') . ($status !== '' ? " with status: {$status}" : '')
                        . ($total !== '' ? ". Total: {$total} {$currency}" : '')
                        . ($shipping !== '' && $shipping !== 'Not shipped' ? ". Shipping: {$shipping}" : '') . '.';
            } elseif ($sallaOrderReferenceMatchedUnverified) {
                $statusReply = $detectedLanguage === 'arabic'
                    ? 'وجدت رقم الطلب، لكن لحماية بياناتك أرسل رقم الجوال المستخدم عند إتمام الشراء حتى أتحقق من حالة الطلب.'
                    : 'I found that order number, but to protect your information, please send the phone number used at checkout so I can verify the order.';
            } elseif ($hasIdentifier) {
                $statusReply = $detectedLanguage === 'arabic'
                    ? 'لم أجد طلبًا يطابق البيانات المرسلة. راجع رقم الطلب أو أرسل رقم الجوال المستخدم وقت الشراء.'
                    : 'I could not find an order matching those details. Please check the order number or send the phone number used at checkout.';
            } else {
                $statusReply = $detectedLanguage === 'arabic'
                    ? 'أكيد أساعدك في متابعة الطلب. أرسل رقم الطلب أو رقم الجوال المستخدم وقت الشراء لأتحقق من حالته.'
                    : 'I can check that for you. Please send the order number or the phone number used at checkout.';
            }

            $statusCheckoutState = is_array($conversation->checkout_state) ? $conversation->checkout_state : [];
            $referenceToVerify = $matchedSallaOrderReference ?: $pendingSallaStatusReference;
            if ($sallaOrderReferenceMatchedUnverified && $referenceToVerify !== '') {
                $statusCheckoutState['pending_salla_status_reference_id'] = $referenceToVerify;
            } else {
                unset($statusCheckoutState['pending_salla_status_reference_id']);
            }
            $verifiedReference = trim((string) ($order['reference_id'] ?? ''));
            $savedCompletedReference = trim((string) ($statusCheckoutState['order_number'] ?? ''));
            if ($order !== [] && $verifiedReference !== '' && $savedCompletedReference === $verifiedReference
                && !empty($statusCheckoutState['order_id'])) {
                // Repair completed checkouts written by older releases that
                // retained the pre-order cart/options alongside the real order.
                $statusCheckoutState = array_intersect_key($statusCheckoutState, array_flip([
                    'status', 'order_id', 'order_number', 'confirmation_state',
                    'order_fingerprint', 'order_product_id', 'order_connection_id',
                ]));
                $statusCheckoutState['status'] = 'completed';
                $statusCheckoutState['confirmation_state'] = 'order_placed';
            }
            if ($statusCheckoutState !== []) {
                $conversation->update(['checkout_state' => $statusCheckoutState]);
            }

            Log::info('SALLA_DETERMINISTIC_STATUS_REPLY', [
                'conversation_id' => $conversation->id,
                'message_id' => $message->id,
                'lookup_found' => $order !== [],
                'identifier_supplied' => $hasIdentifier,
                'provider_called' => false,
            ]);
            $this->sendSallaDeterministicReply($channel, $conversation, $statusReply, $detectedLanguage, 'salla_order_status');
            return;
        }

        // Pass raw order array directly — avoids the lossy string→array round-trip
        // that parseSallaContext() was doing, which silently dropped fields on
        // prefix-mismatch and caused the AI to ask for the order number even
        // when the order had already been found.
        $orderContext = null;
        if ($sallaContext) {
            // sallaContext is a formatted string from SallaService::formatOrderForAI().
            // Re-parse it properly, or better: store the raw array alongside it.
            // For now use the robust parser; the real fix is passing $order directly.
            $orderContext = $this->parseSallaContext($sallaContext);
            // Log what we're sending to AI so we can verify
            Log::info('ProcessAutoReply: order context built for AI', [
                'order_context_fields' => array_keys(is_array($orderContext) ? $orderContext : []),
            ]);
        }

        // Precedence: Bot setting overrides BusinessProfile default fallback
        $effectiveAiInstructions = !empty($bot?->ai_instructions) ? $bot->ai_instructions : ($business?->ai_instructions ?? null);
        $effectiveReplyStyle     = !empty($bot?->reply_style) ? $bot->reply_style : ($business?->reply_style ?? null);
        $effectiveConfidenceThreshold = self::normalizeConfidenceScore(
            !empty($bot?->ai_confidence_threshold) ? $bot->ai_confidence_threshold : ($business?->ai_confidence_threshold ?? 0.80)
        ) ?? 0.80;
        $effectiveEscalationConfig = !empty($bot?->escalation_config) ? $bot->escalation_config : ($business?->escalation_config ?? null);

        // ── COMPUTE EXISTING CHECKOUT FIELD STATUS FOR STRUCTURED STATE ──────
        $checkoutService = app(\App\Services\OrderCheckoutService::class);
        $fieldStatus = $checkoutService->computeFieldStatus(
            is_array($checkoutState) ? $checkoutState : [],
            (bool) $sallaChannel && !empty($checkoutState['salla_product_id']),
            is_array($checkoutState) ? (array) ($checkoutState['salla_required_fields'] ?? []) : []
        );

        // ── STRUCTURED CONVERSATION STATE ────────────────────────────────────
        // Build authoritative structured state for the AI. The AI must NEVER
        // invent or override these values. It may propose literal field spans;
        // application code validates and stores them.
        $structuredState = [
            'customer' => [
                'name' => $checkoutState['full_name'] ?? $checkoutState['customer_name'] ?? null,
                'phone' => $checkoutState['phone'] ?? $checkoutState['customer_phone'] ?? null,
                'address' => $checkoutState['address'] ?? $checkoutState['customer_address'] ?? null,
            ],
            'order' => [
                'product_id' => $checkoutState['salla_product_id'] ?? $referencedProduct['salla_product_id'] ?? null,
                'product_name' => $checkoutState['product_name'] ?? $referencedProduct['name'] ?? null,
                'product_price' => $checkoutState['product_price'] ?? $referencedProduct['price'] ?? null,
                'currency' => $checkoutState['product_currency'] ?? $referencedProduct['currency'] ?? 'SAR',
                'quantity' => $checkoutState['quantity'] ?? 1,
                'confirmation_state' => $checkoutState['confirmation_state'] ?? 'collecting_info',
            ],
            'reply_target' => [
                'platform' => $message->metadata['reply_to_platform'] ?? null,
                'message_id' => $quotedMessageId,
                'resolved_product_id' => $referencedProduct['salla_product_id'] ?? null,
            ],
            'pending_action' => $this->determinePendingAction(is_array($checkoutState) ? $checkoutState : [], $isPlaceOrder, $fieldStatus),
        ];

        // Build business profile context separate from uploaded knowledge
        $businessProfileContext = '';
        if ($business) {
            // Business name
            $businessProfileContext .= "BUSINESS PROFILE\n";
            $businessProfileContext .= "================\n";
            $businessName = $business->business_name ?? 'our business';
            $businessProfileContext .= "Business Name: {$businessName}\n";
            if (filled($business->country)) {
                $businessProfileContext .= "Country: {$business->country}\n";
            }
            if (filled($business->currency)) {
                $businessProfileContext .= "Business currency: {$business->currency}\n";
            }

            if ($bot) {
                $businessProfileContext .= "Bot Name: {$bot->name}\n";
            }

            // Business type
            if (!empty($business->business_type)) {
                $businessProfileContext .= "Business Type: {$business->business_type}\n";
            }

            // Business description/knowledge_base from business profile
            if (!empty($business->knowledge_base)) {
                $businessProfileContext .= "Description: {$business->knowledge_base}\n";
            }

            // Services
            if (!empty($business->services)) {
                if (is_string($business->services)) {
                    $services = $business->services;
                } elseif (is_array($business->services)) {
                    $services = implode("\n- ", $business->services);
                    $services = "- " . $services;
                } else {
                    $services = (string) $business->services;
                }
                $businessProfileContext .= "Services:\n{$services}\n";
            }

            // FAQs
            if (!empty($business->faqs)) {
                if (is_string($business->faqs)) {
                    $faqs = $business->faqs;
                } elseif (is_array($business->faqs)) {
                    $faqItems = [];
                    foreach ($business->faqs as $faq) {
                        if (is_array($faq)) {
                            $q = $faq['question'] ?? $faq['q'] ?? null;
                            $a = $faq['answer'] ?? $faq['a'] ?? null;
                            if ($q && $a) {
                                $faqItems[] = "Q: {$q}\nA: {$a}";
                            }
                        } elseif (is_string($faq)) {
                            $faqItems[] = $faq;
                        }
                    }
                    $faqs = implode("\n\n", $faqItems);
                } else {
                    $faqs = (string) $business->faqs;
                }
                $businessProfileContext .= "FAQs:\n{$faqs}\n";
            }

            // AI instructions (Bot override or Business default)
            if (!empty($effectiveAiInstructions)) {
                $businessProfileContext .= "AI Instructions: {$effectiveAiInstructions}\n";
            }

            // Reply style (Bot override or Business default)
            if (!empty($effectiveReplyStyle)) {
                $businessProfileContext .= "Reply Style: {$effectiveReplyStyle}\n";
            }

            $businessProfileContext .= "\n";
        }

        // ── ORDER CHECKOUT STATE & FIELD EXTRACTION ─────────────────────────
        $checkoutService = app(\App\Services\OrderCheckoutService::class);
        $hasActiveSallaCheckout = (bool) $sallaChannel && !empty($checkoutState['salla_product_id']);
        $addressWasRequested = false;
        if ($hasActiveSallaCheckout && empty($checkoutState['address'] ?? $checkoutState['customer_address'] ?? null)) {
            $lastAiCheckoutPrompt = Message::query()
                ->where('conversation_id', $conversation->id)
                ->where('direction', 'outbound')
                ->where('is_ai', true)
                ->where('id', '<', $message->id)
                ->latest('id')
                ->value('content');
            $addressWasRequested = is_string($lastAiCheckoutPrompt) && (bool) preg_match(
                '/(?:\\b(?:send|provide|share|reply\\s+with|enter)\\b.{0,100}\\b(?:delivery\\s+)?address\\b|\\bwhere\\s+should\\s+(?:i|we)\\s+deliver\\b|(?:ابعت|أرسل|ارسل|اكتب|اذكر|شارك).{0,100}(?:العنوان|عنوان التوصيل|مدينة التوصيل|الشارع))/iu',
                $lastAiCheckoutPrompt
            );
        }
        if (!$isProductBrowseMessage
            && $hasActiveSallaCheckout
            && (empty($checkoutState['postal_code']) || empty($checkoutState['geo_coordinates']))) {
            // Older parser versions stored only the first line of multiline
            // customer addresses. Recover the latest explicit address message
            // from conversation history so a later greeting or field reply
            // doesn't leave the checkout permanently missing its city/ZIP.
            $priorAddressMessage = Message::query()
                ->where('conversation_id', $conversation->id)
                ->where('direction', 'inbound')
                ->where('id', '<', $message->id)
                ->where(function ($query) {
                    $query->where('content', 'like', '%address%')
                        ->orWhere('content', 'like', '%العنوان%')
                        ->orWhere('content', 'like', '%عنوان%');
                })
                ->latest('id')
                ->first();

            if ($priorAddressMessage && is_string($priorAddressMessage->content)) {
                $recoveredCheckoutState = $checkoutService->extractAndMergeState(
                    $conversation,
                    $priorAddressMessage->content,
                    null
                );
                if (!empty($recoveredCheckoutState)) {
                    $conversation->checkout_state = $recoveredCheckoutState;
                    Log::info('ProcessAutoReply: recovered checkout address from earlier inbound message', [
                        'conversation_id' => $conversation->id,
                        'source_message_id' => $priorAddressMessage->id,
                        'postal_code_recovered' => !empty($recoveredCheckoutState['postal_code']),
                        'city_in_address' => app(OrderCheckoutService::class)->resolveDeliveryLocation(
                            (string) ($recoveredCheckoutState['address'] ?? ''),
                            (string) ($recoveredCheckoutState['phone'] ?? '')
                        )['city'] !== null,
                    ]);
                }
            }
        }
        $checkoutStateBeforeIncoming = is_array($conversation->checkout_state)
            ? $conversation->checkout_state
            : [];
        $updatedCheckoutState = $isProductBrowseMessage
            ? []
            : $checkoutService->extractAndMergeState($conversation, $message->content, $referencedProduct, $addressWasRequested);
        if (!empty($updatedCheckoutState)) {
            $hasArabicText = (bool) preg_match('/[\x{0600}-\x{06FF}]/u', (string) $message->content);
            $hasLatinText = (bool) preg_match('/[A-Za-z]/', (string) $message->content);
            $updatedCheckoutState['checkout_language'] = $hasArabicText
                ? 'arabic'
                : ($hasLatinText ? 'english' : ($updatedCheckoutState['checkout_language'] ?? $detectedLanguage));
            $attachmentCoordinates = data_get($message->metadata, 'attachment.coordinates');
            if (is_array($attachmentCoordinates)
                && is_numeric($attachmentCoordinates['lat'] ?? null)
                && is_numeric($attachmentCoordinates['lng'] ?? null)
                && (!empty($updatedCheckoutState['salla_product_id']) || !empty($updatedCheckoutState['commerce_external_id']))) {
                $updatedCheckoutState['geo_coordinates'] = [
                    'lat' => (float) $attachmentCoordinates['lat'],
                    'lng' => (float) $attachmentCoordinates['lng'],
                ];
            }
            // Merge new extraction into existing checkout_state so fields
            // (full_name, phone, address) accumulate across messages rather than
            // being overwritten.  Use array_merge so previously stored values
            // are never lost when the new message contains different data.
            $merged = array_merge(
                is_array($conversation->checkout_state) ? $conversation->checkout_state : [],
                $updatedCheckoutState
            );
            // Provider product/address data can contain malformed byte sequences.
            // Keep one bad field from crashing this reply job while persisting JSON.
            $merged = json_decode(json_encode($merged, JSON_INVALID_UTF8_SUBSTITUTE), true) ?? [];
            $conversation->update(['checkout_state' => $merged]);
        } elseif (!is_null($conversation->checkout_state)) {
            // A confirmation or short follow-up often contains no extractable
            // customer fields. Preserve the existing checkout instead of
            // clearing the data needed to create the order.
            $existingCheckoutState = is_array($conversation->checkout_state)
                ? $conversation->checkout_state
                : [];
            if ($existingCheckoutState !== []) {
                $updatedCheckoutState = $existingCheckoutState;
            } else {
                $conversation->update(['checkout_state' => null]);
            }
        }
        $sallaCheckoutActive = (bool) $sallaChannel && !empty($updatedCheckoutState['salla_product_id']);
        $addressGeocodingStatus = null;
        $resolvedShippingForCheckout = null;

        // Reuse a saved Salla customer profile when the phone matches. This
        // avoids asking returning customers for an email already on file.
        if ($sallaCheckoutActive && !empty($updatedCheckoutState['phone'] ?? $updatedCheckoutState['customer_phone'] ?? '')) {
            try {
                $sallaProfile = app(SallaService::class)->findCustomerForChannel(
                    $sallaChannel,
                    (string) ($updatedCheckoutState['phone'] ?? $updatedCheckoutState['customer_phone'])
                );
                if (empty($updatedCheckoutState['email']) && !empty($sallaProfile['email'])) {
                    $updatedCheckoutState['email'] = $sallaProfile['email'];
                    $updatedCheckoutState['customer_email'] = $sallaProfile['email'];
                }
                if (empty($updatedCheckoutState['address']) && !empty($sallaProfile['location']) && $sallaProfile['location'] !== 'null') {
                    $savedAddress = trim(implode(', ', array_filter([
                        $sallaProfile['location'],
                        $sallaProfile['city'] ?? null,
                        $sallaProfile['country'] ?? null,
                    ], fn ($value) => is_string($value) && trim($value) !== '' && strtolower(trim($value)) !== 'null')));
                    if ($savedAddress !== '') {
                        $updatedCheckoutState['address'] = $savedAddress;
                    }
                }
                $conversation->update(['checkout_state' => $updatedCheckoutState]);
            } catch (\Throwable $e) {
                Log::warning('ProcessAutoReply: could not reuse saved Salla customer details', [
                    'conversation_id' => $conversation->id,
                    'exception_type' => get_class($e),
                ]);
            }
        }

        // Resolve the Salla city ID before geocoding so a city name absent
        // from the freeform address can still provide a safe city-level pin.
        if ($sallaCheckoutActive && !empty($updatedCheckoutState['address'])) {
            $resolvedShippingForCheckout = (new SallaService())->resolveShippingAddressForChannel(
                $sallaChannel,
                (string) $updatedCheckoutState['address'],
                (string) ($updatedCheckoutState['phone'] ?? $updatedCheckoutState['customer_phone'] ?? ''),
                (string) ($updatedCheckoutState['delivery_city'] ?? '')
            );
            if (!empty($resolvedShippingForCheckout['city_id'])) {
                $updatedCheckoutState['delivery_city'] = (string) $resolvedShippingForCheckout['city_id'];
                $updatedCheckoutState['delivery_country_code'] = $resolvedShippingForCheckout['country_code'] ?? null;
                $conversation->update(['checkout_state' => $updatedCheckoutState]);
            }
        }

        // Resolve approximate delivery coordinates from the city alone;
        // a matching street or house number is not required.
        if (
            $sallaCheckoutActive
            && empty($updatedCheckoutState['geo_coordinates'])
            && !empty($updatedCheckoutState['address'])
        ) {
            $geocodingAddress = trim((string) $updatedCheckoutState['address']);
            $deliveryCity = trim((string) ($updatedCheckoutState['delivery_city'] ?? ''));
            $deliveryCountryCode = strtoupper((string) ($updatedCheckoutState['delivery_country_code'] ?? ''));
            $deliveryCountryName = match ($deliveryCountryCode) {
                'SA' => 'Saudi Arabia', 'AE' => 'United Arab Emirates', 'KW' => 'Kuwait',
                'QA' => 'Qatar', 'BH' => 'Bahrain', 'OM' => 'Oman', 'EG' => 'Egypt',
                default => null,
            };
            $cityName = null;
            if ($deliveryCity !== '' && ctype_digit($deliveryCity)) {
                $cityRow = \Illuminate\Support\Facades\DB::table('salla_city_reference_cities as cities')
                    ->join('salla_city_catalogs as catalogs', 'catalogs.country_id', '=', 'cities.country_id')
                    ->where('catalogs.country_code', $deliveryCountryCode ?: 'SA')
                    ->where('cities.salla_city_id', (int) $deliveryCity)
                    ->select('cities.name_en', 'cities.name', 'cities.name_ar')
                    ->first();
                $cityName = trim((string) ($cityRow->name_en ?? $cityRow->name ?? $cityRow->name_ar ?? '')) ?: null;
            } elseif ($deliveryCity !== '') {
                $cityName = $deliveryCity;
            }
            $geocodingAddress = implode(', ', array_values(array_filter([
                $geocodingAddress,
                $cityName,
                $deliveryCountryName,
            ], static fn ($part) => is_string($part) && trim($part) !== '')));
            $geocodingResult = $checkoutService->geocodeDeliveryAddress(
                $geocodingAddress,
                (string) ($updatedCheckoutState['phone'] ?? $updatedCheckoutState['customer_phone'] ?? '')
            );
            $addressGeocodingStatus = $geocodingResult['status'] ?? 'unavailable';
            if ($addressGeocodingStatus === 'found' && !empty($geocodingResult['coordinates'])) {
                $updatedCheckoutState['geo_coordinates'] = $geocodingResult['coordinates'];
                $conversation->update(['checkout_state' => $updatedCheckoutState]);
            }
        }

        // Shopify products can have multiple sellable variants. Resolve a
        // customer's explicit title/SKU/number selection before generating a
        // checkout summary; never let the order request guess a variant.
        $shopifyVariants = [];
        $shopifyVariantSelectionRequired = false;
        $shopifyVariantChosenThisMessage = false;
        $shopifyVariantDataUnavailable = false;
        $sallaProductOptions = [];
        $sallaOptionSelectionRequired = false;
        $sallaOptionChosenThisMessage = false;
        $sallaVariantPriceUnavailable = false;
        $sallaOptionPrompt = null;
        if ($commerceChannel?->type === 'shopify' && !empty($updatedCheckoutState['commerce_external_id'])) {
            $commerceProduct = \App\Models\Product::query()
                ->where('business_id', $conversation->business_id)
                ->where('commerce_channel_id', $commerceChannel->id)
                ->where('commerce_external_id', (string) $updatedCheckoutState['commerce_external_id'])
                ->first();
            $shopifyVariants = \App\Services\ShopifyVariantSelection::available(
                $commerceProduct?->metadata['variants'] ?? []
            );

            if (count($shopifyVariants) === 1) {
                $selectedVariant = $shopifyVariants[0];
            } elseif (count($shopifyVariants) > 1) {
                $selectedVariantId = (string) ($updatedCheckoutState['commerce_variant_id'] ?? '');
                $selectedVariant = collect($shopifyVariants)->firstWhere('id', $selectedVariantId);
                if (!$selectedVariant) {
                    $selectedVariant = \App\Services\ShopifyVariantSelection::match(
                        $shopifyVariants,
                        (string) $message->content
                    );
                    $shopifyVariantChosenThisMessage = $selectedVariant !== null;
                }
                $shopifyVariantSelectionRequired = $selectedVariant === null;
            } else {
                $selectedVariant = null;
                $shopifyVariantSelectionRequired = true;
                $shopifyVariantDataUnavailable = true;
            }

            if ($selectedVariant) {
                $updatedCheckoutState['commerce_variant_id'] = (string) $selectedVariant['id'];
                $updatedCheckoutState['commerce_variant_title'] = (string) ($selectedVariant['title'] ?? '');
                if (isset($selectedVariant['price'])) {
                    $updatedCheckoutState['product_price'] = (string) $selectedVariant['price'];
                }
            }
        }

        // Salla products can require explicit variants/options (for example,
        // clothing size). Resolve them against this store's live product data;
        // never send an order with an arbitrary size. If exactly one value is
        // in stock it is safe to carry it into the visible confirmation summary.
        $shouldInspectSallaOptions = $isPlaceOrder
            || \App\Services\ShopifyOrderIntent::wantsToOrder((string) $message->content)
            || \App\Services\OrderCheckoutService::isExplicitOrderConfirmation((string) $message->content)
            || ($updatedCheckoutState['confirmation_state'] ?? null) === 'selecting_salla_option';
        if ($commerceChannel?->type === 'salla'
            && $shouldInspectSallaOptions
            && !empty($updatedCheckoutState['salla_product_id'])) {
            try {
                $sallaProductOptions = (new SallaService())->getProductOptionsForChannel(
                    $commerceChannel,
                    (string) $updatedCheckoutState['salla_product_id']
                );
                // A new order request starts with no carried-over size or colour. An
                // option chosen in an earlier, abandoned checkout must never silently
                // reappear (the customer is asked again). A pending summary keeps its selections.
                $startsNewSallaOrder = \App\Services\ShopifyOrderIntent::wantsToOrder((string) $message->content)
                    && ($updatedCheckoutState['confirmation_state'] ?? null) !== 'confirmation_pending'
                    && ($updatedCheckoutState['confirmation_state'] ?? null) !== 'selecting_salla_option';
                $savedSelections = $startsNewSallaOrder ? [] : (array) ($updatedCheckoutState['salla_options'] ?? []);
                // Titles are derived from the canonical option-ID map below.
                // Carrying a legacy numeric title list forward caused each
                // extraction pass to append another copy of size/color.
                $savedTitles = [];
                $selectingSallaOption = ($updatedCheckoutState['confirmation_state'] ?? null) === 'selecting_salla_option';

                // ── DETERMINISTIC SALLA OPTION MATCHER ──────────────────────────────
                // Normalise a customer reply string: Arabic-Indic → Latin digits,
                // dash variants and extra whitespace → single space, strip punctuation.
                $normaliseSallaReply = static function (string $raw): string {
                    $s = strtr(mb_strtolower(trim($raw)), [
                        '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
                        '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
                        '–' => '-', '—' => '-',
                    ]);
                    // Replace dash+spaces combos and strip punctuation except letters/digits/space
                    $s = preg_replace('/\s*-\s*/', ' ', $s) ?? $s;
                    $s = preg_replace('/[^\p{L}\p{N} ]+/u', '', $s) ?? $s;
                    return preg_replace('/\s+/', ' ', trim($s)) ?? $s;
                };

                // Strip all spaces for compact comparison ("40m" == "40 m").
                $stripSpaces = static fn (string $s): string => str_replace(' ', '', $s);

                foreach ($sallaProductOptions as $option) {
                    $optionId = (string) $option['id'];
                    $values   = $option['values'];
                    $selectedIds = array_values(array_unique(array_intersect(
                        array_map('strval', (array) ($savedSelections[$optionId] ?? [])),
                        array_column($values, 'id')
                    )));

                    // ── Attempt to pre-fill from the original order message text ──────
                    // Rule 7: if the customer said "jacket size M" in their very first
                    // order message, carry that choice now instead of asking.
                    if ($selectedIds === [] && !$selectingSallaOption && $startsNewSallaOrder) {
                        $prefillText  = $normaliseSallaReply((string) $message->content);
                        $prefillHits  = [];
                        foreach ($values as $vi => $value) {
                            $normVal = $normaliseSallaReply((string) $value['name']);
                            if ($normVal === '') continue;
                            // Whole-word or token match inside the message.
                            if (preg_match('/(?<![^\s])\Q' . preg_quote($normVal, '/') . '\E(?![^\s])/u', $prefillText)
                                || $stripSpaces($prefillText) === $stripSpaces($normVal)
                            ) {
                                $prefillHits[] = (string) $value['id'];
                            }
                        }
                        if (count($prefillHits) === 1) {
                            $selectedIds = $prefillHits;
                            $sallaOptionChosenThisMessage = true;
                        }
                    }

                    // ── Full deterministic reply matching (only during selection state) ──
                    if ($selectedIds === [] && $selectingSallaOption) {
                        $rawReply    = (string) $message->content;
                        $selText     = $normaliseSallaReply($rawReply);
                        $selCompact  = $stripSpaces($selText);

                        // Build per-value normalised names once.
                        $normValues = [];
                        foreach ($values as $vi => $value) {
                            $normValues[$vi] = [
                                'id'      => (string) $value['id'],
                                'normed'  => $normaliseSallaReply((string) $value['name']),
                                'compact' => $stripSpaces($normaliseSallaReply((string) $value['name'])),
                                'raw'     => (string) $value['name'],
                            ];
                        }

                        // ── Rule 3a/3b: index match ──────────────────────────────────
                        // Extract a leading integer from the reply.
                        $index = null;
                        if (preg_match('/^(?:(?:size|option|choice|المقاس|مقاس|الخيار|الاختيار)\s+)?(\d{1,2})(?:\s|$)/u', $selText, $m)) {
                            $index = (int) $m[1] - 1;
                        }

                        // If the reply is JUST an index with no other non-digit tokens,
                        // accept it immediately (Rule 3a).
                        $indexOnlyMatch = null;
                        if ($index !== null && isset($values[$index])) {
                            $afterIndex = trim(preg_replace('/^(?:(?:size|option|choice|المقاس|مقاس|الخيار|الاختيار)\s+)?\d{1,2}\s*/u', '', $selText) ?? '');
                            if ($afterIndex === '') {
                                // Pure index reply.
                                $indexOnlyMatch = (string) $values[$index]['id'];
                            } else {
                                // Rule 3b: index + trailing text — accept if trailing
                                // text is consistent with (or a substring of) that option.
                                $indexedNorm    = $normValues[$index]['normed'];
                                $indexedCompact = $normValues[$index]['compact'];
                                $trailCompact   = $stripSpaces($afterIndex);
                                $consistent     = ($afterIndex === $indexedNorm)
                                    || ($trailCompact === $indexedCompact)
                                    || str_contains($indexedCompact, $trailCompact)
                                    || str_contains($trailCompact, $indexedCompact);
                                if ($consistent) {
                                    $indexOnlyMatch = (string) $values[$index]['id'];
                                }
                            }
                        }

                        if ($indexOnlyMatch !== null) {
                            $selectedIds = [$indexOnlyMatch];
                            $sallaOptionChosenThisMessage = true;
                        } else {
                            // ── Rules 3c/3d/3e: label / token matching ───────────────
                            $labelHits = [];
                            foreach ($normValues as $vi => $nv) {
                                if ($nv['normed'] === '') continue;
                                // 3c: exact full label match (with or without spaces).
                                if ($selText === $nv['normed'] || $selCompact === $nv['compact']) {
                                    $labelHits[] = $nv['id'];
                                    continue;
                                }
                                // 3d/3e: reply is a token that appears in the label OR
                                // the label is a token that appears in the reply.
                                // Guard: token must be a whole word boundary.
                                $selWords   = explode(' ', $selCompact);
                                $labelWords = explode(' ', $nv['compact']);
                                foreach ($selWords as $sw) {
                                    if ($sw !== '' && in_array($sw, $labelWords, true)) {
                                        $labelHits[] = $nv['id'];
                                        break;
                                    }
                                }
                            }
                            $labelHits = array_values(array_unique($labelHits));

                            if (count($labelHits) === 1) {
                                // If we also had an index, make sure they agree.
                                if ($index === null
                                    || !isset($values[$index])
                                    || (string) $values[$index]['id'] === $labelHits[0]
                                ) {
                                    $selectedIds = $labelHits;
                                    $sallaOptionChosenThisMessage = true;
                                } else {
                                    // Index and label disagree → ambiguous, ask again (handled below).
                                    $labelHits = [$labelHits[0], (string) $values[$index]['id']];
                                }
                            }

                            // Multiple hits → ambiguous. Store the candidates so the
                            // re-prompt can show only the matching subset.
                            if (count($labelHits) > 1 && $selectedIds === []) {
                                $updatedCheckoutState['salla_option_ambiguous_ids'] = $labelHits;
                            } elseif ($selectedIds !== []) {
                                unset($updatedCheckoutState['salla_option_ambiguous_ids']);
                            }
                        }
                    }

                    if ($selectedIds === []) {
                        $sallaOptionSelectionRequired = true;
                        $sallaOptionPrompt ??= $option;
                        continue;
                    }

                    // Clear ambiguous hint on success.
                    unset($updatedCheckoutState['salla_option_ambiguous_ids']);
                    $savedSelections[$optionId] = $selectedIds;
                    $selectedValue = collect($values)->firstWhere('id', $selectedIds[0]);
                    if ($selectedValue) {
                        $savedTitles[$optionId] = trim($option['name'] . ': ' . $selectedValue['name']);
                    }
                }

                $variantOptions = array_values(array_filter(
                    $sallaProductOptions,
                    static fn (array $option): bool => in_array(($option['purpose'] ?? ''), ['variant', 'variants'], true)
                ));
                if ($variantOptions !== []) {
                    $selectedVariantValueIds = [];
                    foreach ($variantOptions as $variantOption) {
                        $variantOptionId = (string) $variantOption['id'];
                        $selected = array_values(array_intersect(
                            array_map('strval', (array) ($savedSelections[$variantOptionId] ?? [])),
                            array_column((array) $variantOption['values'], 'id')
                        ));
                        if (count($selected) !== 1) {
                            $selectedVariantValueIds = [];
                            break;
                        }
                        $selectedVariantValueIds[] = $selected[0];
                    }

                    if (count($selectedVariantValueIds) === count($variantOptions)) {
                        try {
                            $variantPricing = (new SallaService())->getVariantPriceForOptions(
                                $commerceChannel,
                                (string) $updatedCheckoutState['salla_product_id'],
                                $selectedVariantValueIds
                            );
                            if ($variantPricing) {
                                $updatedCheckoutState['product_price'] = (string) $variantPricing['price'];
                                $updatedCheckoutState['product_currency'] = (string) $variantPricing['currency'];
                                $updatedCheckoutState['salla_variant_id'] = (string) $variantPricing['variant_id'];
                                unset($updatedCheckoutState['salla_variant_price_unresolved']);
                            } else {
                                $sallaVariantPriceUnavailable = true;
                                $updatedCheckoutState['salla_variant_price_unresolved'] = true;
                            }
                        } catch (\Throwable $priceException) {
                            $sallaVariantPriceUnavailable = true;
                            $updatedCheckoutState['salla_variant_price_unresolved'] = true;
                            Log::warning('ProcessAutoReply: could not resolve selected Salla variant price', [
                                'conversation_id' => $conversation->id,
                                'connection_id' => $commerceChannel->id,
                                'exception_type' => get_class($priceException),
                            ]);
                        }
                    }
                }

                if ($savedSelections !== []) {
                    $updatedCheckoutState['salla_options'] = $savedSelections;
                    $updatedCheckoutState['salla_option_titles'] = array_values($savedTitles);
                    // Clear selection-loop counters on a successful match.
                    if ($sallaOptionChosenThisMessage) {
                        unset(
                            $updatedCheckoutState['salla_option_failed_attempts'],
                            $updatedCheckoutState['salla_option_ambiguous_ids']
                        );
                    }
                } elseif ($startsNewSallaOrder) {
                    // Drop the previous checkout's size/colour and its variant price.
                    unset(
                        $updatedCheckoutState['salla_options'],
                        $updatedCheckoutState['salla_option_titles'],
                        $updatedCheckoutState['salla_variant_id'],
                        $updatedCheckoutState['salla_variant_price'],
                        $updatedCheckoutState['salla_option_failed_attempts'],
                        $updatedCheckoutState['salla_option_ambiguous_ids'],
                        $updatedCheckoutState['salla_offered_options']
                    );
                }
            } catch (\Throwable $e) {
                if ($isPlaceOrder || ($updatedCheckoutState['confirmation_state'] ?? null) === 'selecting_salla_option') {
                    // An option lookup failure must not silently fall back to
                    // the base product price or allow an unpriced variant order.
                    $sallaVariantPriceUnavailable = true;
                    $updatedCheckoutState['salla_variant_price_unresolved'] = true;
                    $conversation->update(['checkout_state' => $updatedCheckoutState]);
                }
                Log::warning('ProcessAutoReply: could not inspect Salla product options', [
                    'conversation_id' => $conversation->id,
                    'connection_id' => $commerceChannel->id,
                    'exception_type' => get_class($e),
                ]);
            }
        }

        $resolvedShipping = null;
        if ($commerceChannel?->type === 'salla'
            && !empty($updatedCheckoutState['address'])) {
            $resolvedShipping = $resolvedShippingForCheckout ?? (new SallaService())->resolveShippingAddressForChannel(
                $commerceChannel,
                (string) $updatedCheckoutState['address'],
                (string) ($updatedCheckoutState['phone'] ?? ''),
                (string) ($updatedCheckoutState['delivery_city'] ?? '')
            );
            if ($resolvedShipping && !empty($resolvedShipping['city_id'])) {
                $updatedCheckoutState['delivery_city'] = (string) $resolvedShipping['city_id'];
                $updatedCheckoutState['delivery_country_code'] = $resolvedShipping['country_code'] ?? null;
            } else {
                unset($updatedCheckoutState['delivery_city']);
                unset($updatedCheckoutState['delivery_country_code']);
            }
        }

        // Quote COD shipping before showing the final summary so the amount the
        // customer confirms includes the courier fee. Recompute it on every
        // checkout turn; the quote is reused by the immediately following
        // order request and is bound to the exact address and resolved city.
        if ($commerceChannel?->type === 'salla'
            && !empty($updatedCheckoutState['salla_product_id'])
            && !empty($updatedCheckoutState['full_name'])
            && !empty($updatedCheckoutState['phone'] ?? $updatedCheckoutState['customer_phone'])
            && !empty($updatedCheckoutState['address'])
            && !empty($resolvedShipping['city_id'])) {
            try {
                $shippingQuote = (new SallaService())->estimateCodShippingQuoteForChannel(
                    $commerceChannel,
                    $resolvedShipping,
                    $updatedCheckoutState
                );
            } catch (\Throwable $quoteException) {
                $shippingQuote = null;
                Log::warning('ProcessAutoReply: Salla COD shipping estimate unavailable before order summary', [
                    'connection_id' => $commerceChannel->id,
                    'exception_type' => get_class($quoteException),
                ]);
            }
            if ($shippingQuote) {
                $updatedCheckoutState['salla_shipping_quote'] = array_merge($shippingQuote, [
                    'city_id' => (int) $resolvedShipping['city_id'],
                    'address_hash' => hash('sha256', trim((string) $updatedCheckoutState['address'])),
                    'quoted_at' => now()->timestamp,
                ]);
            } else {
                unset($updatedCheckoutState['salla_shipping_quote']);
            }
        } elseif (empty($updatedCheckoutState['salla_product_id']) || empty($updatedCheckoutState['address'])) {
            unset($updatedCheckoutState['salla_shipping_quote']);
        }

        $checkoutState = $updatedCheckoutState;
        $checkoutDataChangedThisTurn = $this->orderSummaryFingerprint($checkoutStateBeforeIncoming)
            !== $this->orderSummaryFingerprint($updatedCheckoutState);
        $fieldStatus = $checkoutService->computeFieldStatus(
            $updatedCheckoutState,
            $sallaCheckoutActive,
            (array) ($updatedCheckoutState['salla_required_fields'] ?? [])
        );
        $hasValidOrderSummary = $this->hasValidOrderSummaryForConfirmation(
            $conversation,
            $message,
            $updatedCheckoutState
        );
        $confirmationStateBeforeUpdate = $updatedCheckoutState['confirmation_state'] ?? null;
        // A Salla option prompt is an explicit numbered-choice flow. Interpret
        // the customer's next message as a checkout answer (or a clarification
        // retry) before applying generic AI confidence/intent escalation.
        // This keeps a valid reply such as "2" from being handed off just
        // because the model labels it as a low-confidence generic question.
        $isSallaOptionFollowUp = $confirmationStateBeforeUpdate === 'selecting_salla_option';
        if ($isSallaOptionFollowUp) {
            $intent = 'place_order';
            // A number-only answer has no detectable language. Keep the
            // language used by the option prompt so the next checkout reply
            // does not unexpectedly switch to English mid-conversation.
            if (!preg_match('/[\x{0600}-\x{06FF}]/u', (string) $message->content)) {
                $previousOptionPrompt = Message::query()
                    ->where('conversation_id', $conversation->id)
                    ->where('direction', 'outbound')
                    ->where('is_ai', true)
                    ->where('id', '<', $message->id)
                    ->latest('id')
                    ->value('content');
                if (is_string($previousOptionPrompt)
                    && preg_match('/[\x{0600}-\x{06FF}]/u', $previousOptionPrompt)) {
                    $detectedLanguage = 'arabic';
                }
            }
        }
        $updatedCheckoutState = $checkoutService->updateConfirmationState(
            $updatedCheckoutState,
            $message->content,
            (bool) ($fieldStatus['is_complete'] ?? false),
            $hasValidOrderSummary
        );
        if ($shopifyVariantSelectionRequired) {
            $updatedCheckoutState['confirmation_state'] = 'selecting_variant';
            unset(
                $updatedCheckoutState['confirmation_summary_message_id'],
                $updatedCheckoutState['confirmation_summary_fingerprint'],
                $updatedCheckoutState['confirmation_summary_sent_at']
            );
        }
        if ($sallaOptionSelectionRequired) {
            $updatedCheckoutState['confirmation_state'] = 'selecting_salla_option';
            unset(
                $updatedCheckoutState['confirmation_summary_message_id'],
                $updatedCheckoutState['confirmation_summary_fingerprint'],
                $updatedCheckoutState['confirmation_summary_sent_at']
            );
        } elseif ($sallaOptionChosenThisMessage && !empty($fieldStatus['is_complete'])) {
            $updatedCheckoutState['confirmation_state'] = 'confirmation_pending';
        }
        $checkoutState = $updatedCheckoutState;
        if (!empty($updatedCheckoutState)) {
            $conversation->update(['checkout_state' => $updatedCheckoutState]);
        }

        // The AI context was assembled before deterministic extraction; refresh
        // its checkout facts so it asks only for information still missing.
        $structuredState['customer']['email'] = $updatedCheckoutState['email'] ?? $updatedCheckoutState['customer_email'] ?? null;
        $structuredState['customer']['postal_code'] = $updatedCheckoutState['postal_code'] ?? null;
        $structuredState['customer']['geo_coordinates'] = $updatedCheckoutState['geo_coordinates'] ?? null;
        $structuredState['customer']['building_number'] = $updatedCheckoutState['building_number'] ?? null;
        $structuredState['customer']['short_address'] = $updatedCheckoutState['short_address'] ?? null;
        $structuredState['customer']['additional_number'] = $updatedCheckoutState['additional_number'] ?? null;
        $structuredState['pending_action'] = $this->determinePendingAction($updatedCheckoutState, $isPlaceOrder, $fieldStatus);

        Log::info('ORDER_INFO_CHECK', [
            'conversation_id' => $conversation->id,
            'known_fields'    => array_keys($fieldStatus['known_fields']),
            'missing_fields'  => $fieldStatus['missing_fields'],
            'is_complete'     => $fieldStatus['is_complete'],
        ]);

        $autoOrderConfirmationEnabled = $channel->auto_order_confirmation_enabled !== null
            ? (bool) $channel->auto_order_confirmation_enabled
            : (bool) ($business?->auto_order_confirmation_enabled ?? true);

        // Explicit confirmation is necessary; complete checkout fields alone never authorize an order.
        $containsConfirmPhrase = OrderCheckoutService::isExplicitOrderConfirmation($message->content);
        if ($containsConfirmPhrase || $hasValidOrderSummary) {
            Log::info('ProcessAutoReply: checkout confirmation gate evaluated', [
                'conversation_id' => $conversation->id,
                'message_id' => $message->id,
                'provider' => $commerceChannel?->type,
                'connection_id' => $commerceChannel?->id,
                'contains_confirmation_phrase' => $containsConfirmPhrase,
                'has_valid_sent_summary' => $hasValidOrderSummary,
                'confirmation_state_before' => $confirmationStateBeforeUpdate,
                'confirmation_state_after' => $updatedCheckoutState['confirmation_state'] ?? null,
                'checkout_complete' => (bool) ($fieldStatus['is_complete'] ?? false),
                'missing_fields' => array_values($fieldStatus['missing_fields'] ?? []),
                'product_present' => !empty($updatedCheckoutState['salla_product_id']),
                'variant_present' => !empty($updatedCheckoutState['commerce_variant_id']),
                'name_present' => !empty($updatedCheckoutState['full_name'] ?? $updatedCheckoutState['customer_name'] ?? ''),
                'phone_present' => !empty($updatedCheckoutState['customer_phone'] ?? $updatedCheckoutState['phone'] ?? ''),
                'address_present' => !empty($updatedCheckoutState['address'] ?? $updatedCheckoutState['customer_address'] ?? ''),
                'auto_order_confirmation_enabled' => (bool) $autoOrderConfirmationEnabled,
                'is_product_browse_message' => (bool) $isProductBrowseMessage,
            ]);
        }

        // Explicit cancel is deterministic: it clears the in-progress checkout
        // (product, options, details, summary) without calling the model. It only
        // applies while a checkout is active and no order has been placed.
        if (\App\Services\OrderCheckoutService::isExplicitOrderCancellation((string) $message->content)
            && (!empty($checkoutState['salla_product_id']) || !empty($checkoutState['confirmation_state']))
            && empty($checkoutState['order_id'])) {
            $conversation->update(['checkout_state' => null]);
            $cancelReply = Message::create([
                'conversation_id' => $conversation->id,
                'content' => $detectedLanguage === 'arabic'
                    ? 'تم إلغاء الطلب الحالي، ولم يتم تنفيذ أي شيء. تقدر تبدأ طلب جديد في أي وقت.'
                    : 'Okay, I have cancelled the current order. Nothing was placed. You can start a new order any time.',
                'direction' => 'outbound',
                'status' => 'auto',
                'is_ai' => false,
                'source' => 'checkout_cancelled',
                'send_status' => 'pending',
            ]);
            if ($channel->user_id) {
                broadcast(new \App\Events\MessageReceived($cancelReply, $conversation, $channel->user_id));
            }
            Log::info('ProcessAutoReply: checkout cancelled by customer', ['conversation_id' => $conversation->id]);
            $this->sendReply($channel, $conversation, $cancelReply);
            return;
        }

        // Once the customer explicitly confirms a Salla checkout, do not ask
        // an LLM to decide whether that confirmation counts. Validate the
        // saved summary and fields locally, then submit or explain the exact
        // gate that prevents submission.
        if ($sallaChannel && $containsConfirmPhrase) {
            Log::info('SALLA_DETERMINISTIC_CONFIRM_ROUTE', [
                'conversation_id' => $conversation->id,
                'message_id' => $message->id,
                'auto_submission_enabled' => (bool) $autoOrderConfirmationEnabled,
                'checkout_complete' => (bool) ($fieldStatus['is_complete'] ?? false),
                'summary_valid' => (bool) $hasValidOrderSummary,
                'shipping_quote_present' => !empty($updatedCheckoutState['salla_shipping_quote']),
                'provider_called' => false,
            ]);
            if ($this->handleSallaConfirmationWithoutAi(
                $channel,
                $conversation,
                $commerceChannel,
                $updatedCheckoutState,
                $fieldStatus,
                $hasValidOrderSummary,
                (bool) $autoOrderConfirmationEnabled,
                $detectedLanguage
            )) {
                return;
            }
        }

        // Starting or continuing a Salla checkout is also deterministic:
        // collect all missing fields in one turn and show a persisted summary
        // without depending on the AI service being available.
        if ($sallaChannel
            && $commerceChannel?->type === 'salla'
            && !empty($updatedCheckoutState['salla_product_id'])
            && !$containsConfirmPhrase
            && !$isOrderStatus
            && !$isProductBrowseMessage
            && ($isPlaceOrder || $isSallaOptionFollowUp || $sallaOptionChosenThisMessage
                || ($hasActiveSallaCheckout && $incomingCheckoutDetails))) {
            if ($this->handleSallaCheckoutWithoutAi(
                $channel,
                $conversation,
                $commerceChannel,
                $updatedCheckoutState,
                $fieldStatus,
                $sallaOptionSelectionRequired,
                $sallaOptionPrompt,
                $detectedLanguage,
                $sallaVariantPriceUnavailable
            )) {
                return;
            }
        }
        $realOrderId = $updatedCheckoutState['order_id'] ?? null;

        // Ignore legacy internal/fake order IDs left by earlier checkout runs.
        if ($realOrderId && (str_starts_with($realOrderId, 'ORD-') || ($updatedCheckoutState['external_source'] ?? '') === 'internal')) {
            Log::info('ProcessAutoReply: ignoring legacy fake order ID from pre-fix checkout state', [
                'conversation_id' => $conversation->id,
                'legacy_order_id' => $realOrderId,
            ]);
            $realOrderId = null;
            unset($updatedCheckoutState['order_id'], $updatedCheckoutState['id'], $updatedCheckoutState['order_number'], $updatedCheckoutState['has_order']);
            if (($updatedCheckoutState['status'] ?? '') === 'completed') {
                $updatedCheckoutState['status'] = 'in_progress';
            }
            $conversation->update(['checkout_state' => $updatedCheckoutState]);
        }

        $orderCreationFailedReason = null;

        // ── CUSTOMER RESOLUTION & CONTEXT (Phase 2) ──────────────────────────
        // Link the conversation to its business-scoped Customer, using the
        // checkout flow's collected phone to unify phone-less channels
        // (e.g. Instagram) with the customer's WhatsApp/Salla record.
        try {
            $customer = app(\App\Services\CustomerService::class)->enrichFromCheckoutState($conversation);
        } catch (\Throwable $e) {
            Log::warning('ProcessAutoReply: customer resolution failed', [
                'conversation_id' => $conversation->id,
                'exception_type' => get_class($e),
            ]);
            $customer = null;
        }

        // AI-facing customer context — name and tags ONLY. Internal identifiers,
        // scores, and platform secrets are deliberately excluded.
        $customerContext = null;
        if ($customer) {
            $customerContext = array_filter([
                'name' => $customer->name,
                'tags' => is_array($customer->tags) ? array_values(array_filter($customer->tags, 'is_string')) : null,
            ], fn ($v) => $v !== null && $v !== []);

            // Customer linked but nothing displayable yet — still emit the
            // section so the AI knows the name is unknown and must not invent one.
            if (empty($customerContext)) {
                $customerContext = ['name' => null];
            }
        }

        // Keep source identifiers for the owner/manager audit without retaining
        // retrieved document text or a copy of the prompt.
        $knowledgeFileIds = [];
        $knowledgeChunkIds = [];
        $knowledgeFailsafeEnabled = $business
            && \App\Models\FeatureFlag::isEnabled('knowledge_embedding_failsafe', (int) $business->id);
        $knowledgeShadowEnabled = $business && !$isSallaFlow
            && \App\Models\FeatureFlag::isEnabled('knowledge_relevance_floor_shadow', (int) $business->id);
        $knowledgeStatus = $knowledgeFailsafeEnabled ? 'unavailable' : null;

        $context = [
            'business_id' => $business?->id,
            'conversation_id' => $conversation->id,
            'message_id' => $message->id,
            'ai_operation_id' => \App\Services\AiGateway::stableOperationId('process-auto-reply:' . $message->id),
            'ai_task' => 'customer_reply',
            'business_name'  => $channel->business?->business_name ?? 'our business',
            'platform'       => $channel->type,
            'language'       => $detectedLanguage,
            'salla_exclusive_mode' => $isSallaFlow,
            'business_profile' => $isSallaFlow ? '' : $businessProfileContext,
            // ── CHANNEL & ACCOUNT CONTEXT ──
            // Explicitly grounds the AI in the account it is speaking from —
            // prevents cross-account/store confusion when a business runs
            // several channels of the same type.
            'channel'        => [
                'type' => $channel->type,
                'name' => $channel->page_name ?? null,
            ],
            // The specific store whose live data (if any) was injected below.
            'store'          => $sallaChannel ? [
                'type' => 'salla', 'name' => $sallaChannel->page_name ?? null,
            ] : ($commerceChannel ? [
                'type' => $commerceChannel->type, 'name' => $commerceChannel->page_name ?? null,
            ] : null),
            'customer'       => $customerContext,
            'knowledge_base' => (function() use ($business, $bot, $channel, $message, $isSallaFlow, $knowledgeFailsafeEnabled, $knowledgeShadowEnabled, &$knowledgeStatus, &$knowledgeFileIds, &$knowledgeChunkIds) {
                if (!$business || $isSallaFlow) {
                    return '';
                }
                $vectorSearch = app(\App\Services\VectorSearchService::class);

                // 1. Get embedding for the user's message
                try {
                    $embeddingsService = app(\App\Services\EmbeddingsService::class);
                    $queryEmbedding = $embeddingsService->embedChunk($message->content, $business, \App\Services\AiGateway::stableOperationId('knowledge-query:' . $message->id));
                } catch (\Throwable $exception) {
                    if (!$knowledgeFailsafeEnabled) {
                        throw $exception;
                    }

                    $knowledgeStatus = 'unavailable';
                    Log::warning('ProcessAutoReply: knowledge embedding unavailable', [
                        'business_id' => $business->id,
                        'exception_type' => get_class($exception),
                    ]);
                    return '';
                }
                
                if (empty($queryEmbedding)) {
                    if ($knowledgeFailsafeEnabled) {
                        $knowledgeStatus = 'unavailable';
                    }
                    return ''; // Fallback if embedding fails
                }

                if ($knowledgeFailsafeEnabled) {
                    $knowledgeStatus = 'no_match';
                }

                // 2. Determine allowed knowledge file IDs for the Bot + Channel.
                // $allowedFileIds = null means "no bot resolved → unrestricted
                // business knowledge" (intentionally global). When a bot IS
                // resolved, retrieval is STRICTLY bot-scoped — a bot with no
                // (channel-matching) assignments gets NO knowledge, never a
                // silent fallback to the whole business knowledge base.
                $allowedFileIds = null;
                if ($bot) {
                    try {
                        // For a bot, query explicitly assigned knowledge files (shared across channels OR channel-specific)
                        $allowedFileIds = \App\Models\BotKnowledgeAssignment::where('bot_id', $bot->id)
                            ->where(function ($q) use ($channel) {
                                $q->whereNull('channel_id')
                                  ->orWhere('channel_id', $channel->id);
                            })
                            ->pluck('business_knowledge_file_id')
                            ->unique()
                            ->values()
                            ->toArray();
                    } catch (\Throwable $e) {
                        // Fail CLOSED: on infra failure deny bot knowledge rather
                        // than leaking unassigned business files into its context.
                        Log::error('ProcessAutoReply: BotKnowledgeAssignment query failed — denying bot knowledge (fail-closed)', [
                            'exception_type' => get_class($e),
                            'bot_id' => $bot->id,
                        ]);
                        if ($knowledgeFailsafeEnabled) {
                            $knowledgeStatus = 'filtered';
                        }
                        return '';
                    }

                    if ($knowledgeFailsafeEnabled && empty($allowedFileIds)) {
                        $knowledgeStatus = 'filtered';
                        return '';
                    }
                }

                // 3. Search for relevant chunks
                $relevantChunks = $vectorSearch->search(
                    $queryEmbedding,
                    $business->id,
                    5,
                    $allowedFileIds,
                    $embeddingsService->activeProvider(),
                    $embeddingsService->activeModel(),
                    $embeddingsService->dimension()
                );

                if ($knowledgeShadowEnabled) {
                    $scores = array_map(fn ($chunk) => (float) ($chunk->similarity_score ?? 0), $relevantChunks);
                    $topScore = $scores ? max($scores) : null;
                    $scoreBucket = match (true) {
                        $topScore === null => 'none',
                        $topScore < 0.55 => 'below_055',
                        $topScore < 0.65 => 'from_055_to_064',
                        $topScore < 0.75 => 'from_065_to_074',
                        default => 'at_least_075',
                    };
                    Log::info('Knowledge relevance shadow sample', [
                        'business_id' => $business->id,
                        'top_score_bucket' => $scoreBucket,
                        'top_chunk_count' => count($scores),
                        'would_filter_below_055' => count(array_filter($scores, fn ($score) => $score < 0.55)),
                        'would_filter_below_065' => count(array_filter($scores, fn ($score) => $score < 0.65)),
                        'would_filter_below_075' => count(array_filter($scores, fn ($score) => $score < 0.75)),
                    ]);
                }

                if ($knowledgeFailsafeEnabled && empty($relevantChunks)
                    && (($vectorSearch->lastSearchStats()['skipped_dimension_mismatch_count'] ?? 0)
                        + ($vectorSearch->lastSearchStats()['skipped_metadata_mismatch_count'] ?? 0)) > 0) {
                    $knowledgeStatus = 'unavailable';
                }

                if (empty($relevantChunks)) {
                    return '';
                }

                $knowledgeText = '';
                foreach ($relevantChunks as $index => $chunk) {
                    $knowledgeChunkIds[] = (int) $chunk->id;
                    if ($chunk->business_knowledge_file_id) {
                        $knowledgeFileIds[] = (int) $chunk->business_knowledge_file_id;
                    }
                    $fileName = $chunk->file ? $chunk->file->filename : 'Knowledge Base';
                    $knowledgeText .= "\n[Source: {$fileName}] (Relevance Top " . ($index + 1) . ")\n";
                    $knowledgeText .= $chunk->content . "\n";
                }

                if ($knowledgeFailsafeEnabled) {
                    $knowledgeStatus = 'ok';
                }

                return $knowledgeText;
            })() ?? '',
            'connected_channels' => (function() use ($business) {
                if (!$business) return '';
                $channels = $business->channels()->where('status', 'connected')->pluck('type')->unique()->toArray();
                if (empty($channels)) return '';
                
                $formatted = array_map(function($c) {
                    return ucfirst($c); // e.g. "Instagram", "Whatsapp", "Salla", "Shopify"
                }, $channels);
                
                return implode(', ', $formatted);
            })(),
            'order_data'     => $orderContext,
            'products'       => $productsContext ?? null,
            'salla_products_aggregate' => $productsAggregateContext,
            'salla_orders_aggregate'   => $ordersAggregateContext,
            'salla_connected'          => (bool) $sallaChannel,
            'commerce_store_connected' => (bool) $commerceChannel,
            'commerce_context' => [
                'status' => $commerceContext['status'] ?? 'unresolved',
                'resolution_source' => $commerceContext['source'] ?? 'unresolved',
                'provider' => $commerceChannel?->type,
                'store_name' => $commerceChannel?->page_name,
            ],
            'commerce_context_instruction' => empty($commerceChannel)
                ? 'No commerce store is resolved for this conversation. Do not search or mention products, customers, orders, inventory, or perform checkout. If the customer asks for store data, ask which connected store/catalog they mean; never choose one yourself.'
                : 'Use only the resolved commerce connection above for every product, customer, inventory, order, and checkout operation. Never use another store or ask the customer to choose a store.',
            // CRITICAL ISSUE — REPLY-TO-PRODUCT CONTEXT: the product the
            // customer deterministically referenced by replying to its image,
            // if any. See getUltimateSystemPrompt() — when present, the AI
            // must never ask "which product" again.
            'referenced_product'       => $referencedProduct,
            'referenced_product_resolution' => $referenceResolution['resolution'],
            'referenced_product_candidates' => $referenceResolution['candidates'],
            'referenced_product_instruction' => match ($referenceResolution['resolution']) {
                'ambiguous' => 'Ask the customer to choose one of the numbered recent products below. Include each product name and price. Do not start checkout until one is selected.',
                'resolved_recent' => 'This product was inferred from one recent product message, not an explicit quote. Confirm the product with the customer before placing an order.',
                'resolved' => $referenceIntent === 'place_order' ? 'The exact quoted product is resolved. Collect missing order details and show the order summary before creating the order.' : 'Answer the product question using only this resolved product.',
                default => $referenceResolution['is_pointer'] ? 'No recent product can be safely resolved. Ask which product they mean and offer to show the catalog.' : null,
            },
            // Active partial order state (persisted across turns so the AI
            // knows which fields have already been collected and won't re-ask).
            'cart'                     => !empty($updatedCheckoutState) ? $updatedCheckoutState : null,
            'checkout_extraction'      => ($sallaCheckoutActive
                && ($updatedCheckoutState['confirmation_state'] ?? null) !== 'selecting_salla_option'
                && !empty($fieldStatus['missing_fields'])) ? [
                'fields' => ['full_name', 'phone', 'email', 'address', 'postal_code', 'building_number', 'street_number', 'block', 'district', 'short_address', 'additional_number'],
                'language' => $detectedLanguage,
            ] : null,
            'incoming_attachment'      => ($attachment = data_get($message->metadata, 'attachment'))
                ? [
                    'type' => $attachment['type'] ?? 'unknown',
                    'status' => $attachment['status'] ?? 'received',
                    'has_readable_customer_text' => trim((string) $message->content) !== '',
                    'has_location_coordinates' => is_array($attachment['coordinates'] ?? null),
                    'analysis' => [
                        'description' => mb_substr((string) data_get($message->metadata, 'attachment_analysis.description', ''), 0, 5000),
                        'extracted_text' => mb_substr((string) data_get($message->metadata, 'attachment_analysis.extracted_text', ''), 0, 5000),
                    ],
                ]
                : null,
            'known_fields'             => $fieldStatus['known_fields'],
            'missing_fields'           => $fieldStatus['missing_fields'],
            'is_checkout_complete'     => $fieldStatus['is_complete'],
            'real_order_id'            => $realOrderId,
            // When order creation failed (e.g. city couldn't be matched), tell the AI
            // so it prompts the customer for the missing/clarifying information instead
            // of falsely claiming the order was placed.
            'order_creation_failed_reason' => $orderCreationFailedReason,
            // Inject bot AI preferences (Bot overrides BusinessProfile)
            'ai_provider'                  => !empty($bot?->ai_provider) ? $bot->ai_provider : ($business?->ai_provider ?? null),
            'ai_model'                     => !empty($bot?->ai_model) ? $bot->ai_model : ($business?->ai_model ?? null),
            'ai_tone_style'                => $effectiveToneStyle,
            'ai_confidence_threshold'      => $effectiveConfidenceThreshold,
            'escalation_config'            => $effectiveEscalationConfig,
            // Structured conversation state — AI must NEVER invent or override these values
            'structured_state'             => $structuredState,
        ];

        // Do not change the prompt unless this business has explicitly opted
        // into the default-off retrieval failsafe. Salla conversations keep
        // their existing commerce-only prompt contract.
        if ($knowledgeFailsafeEnabled && !$isSallaFlow) {
            $context['knowledge_status'] = $knowledgeStatus ?? 'unavailable';
        }

        // Resolve narrow, attribute-specific catalog questions from the
        // selected Salla store's synced products. If the requested item or
        // attribute is absent from that data, do not ask a vague clarification
        // or let the model invent a match; show actual available products.
        $isSpecificProductQuestion = (bool) preg_match(
            '/(?:how\s+much|what(?:\'s|\s+is)\s+the\s+price|price\s+of|do\s+you\s+have|is\s+there|available\s+in|كم\s+سعر|بكام|سعر\s+|عندكم|هل\s+يوجد|متوفر)/iu',
            (string) $message->content
        );
        if ($sallaChannel && !$isPlaceOrder && !$isProductAggregate && $isSpecificProductQuestion
            && is_array($productsContext) && $productsContext !== []) {
            $categoryAliases = [
                ['jacket', 'جاكيت', 'جاكت'], ['blouse', 'بلوزة'],
                ['skirt', 'تنورة'], ['dress', 'فستان'], ['pants', 'trousers', 'بنطلون'],
                ['shirt', 'قميص'], ['t-shirt', 't shirt', 'تيشيرت'], ['shoes', 'shoe', 'حذاء', 'أحذية'],
            ];
            $requestedCategory = null;
            foreach ($categoryAliases as $aliases) {
                foreach ($aliases as $alias) {
                    if (preg_match('/(?<![\pL\pN])' . preg_quote($alias, '/') . '(?![\pL\pN])/iu', (string) $message->content)) {
                        $requestedCategory = $aliases;
                        break 2;
                    }
                }
            }

            if ($requestedCategory !== null) {
                $matchingProducts = array_values(array_filter($productsContext, static function ($product) use ($requestedCategory): bool {
                    if (!is_array($product)) return false;
                    $searchable = mb_strtolower(implode(' ', [
                        (string) ($product['name'] ?? ''),
                        (string) ($product['description'] ?? ''),
                        json_encode($product['variants'] ?? [], JSON_UNESCAPED_UNICODE) ?: '',
                        json_encode($product['options'] ?? [], JSON_UNESCAPED_UNICODE) ?: '',
                    ]));
                    foreach ($requestedCategory as $alias) {
                        if (preg_match('/(?<![\pL\pN])' . preg_quote(mb_strtolower($alias), '/') . '(?![\pL\pN])/iu', $searchable)) {
                            return true;
                        }
                    }
                    return false;
                }));

                $requestedAttributes = [];
                $attributeAliases = [
                    ['black', 'أسود', 'اسود'], ['white', 'أبيض', 'ابيض'], ['red', 'أحمر', 'احمر'],
                    ['blue', 'أزرق', 'ازرق'], ['green', 'أخضر', 'اخضر'], ['beige', 'بيج'],
                ];
                foreach ($attributeAliases as $aliases) {
                    foreach ($aliases as $alias) {
                        if (preg_match('/(?<![\pL\pN])' . preg_quote($alias, '/') . '(?![\pL\pN])/iu', (string) $message->content)) {
                            $requestedAttributes[] = $aliases;
                            break;
                        }
                    }
                }
                if (preg_match('/\b(?:size\s*)?(xs|s|m|l|xl|xxl|xxxl|\d{2})\b|مقاس\s*(xs|s|m|l|xl|xxl|xxxl|\d{2})/iu', (string) $message->content, $sizeMatch)) {
                    $requestedSize = strtoupper((string) ($sizeMatch[1] ?: $sizeMatch[2]));
                    $requestedAttributes[] = [$requestedSize];
                }

                $attributeMatch = $requestedAttributes === [] || array_filter($matchingProducts, static function ($product) use ($requestedAttributes): bool {
                    $searchable = mb_strtolower(implode(' ', [
                        (string) ($product['name'] ?? ''), (string) ($product['description'] ?? ''),
                        json_encode($product['variants'] ?? [], JSON_UNESCAPED_UNICODE) ?: '',
                        json_encode($product['options'] ?? [], JSON_UNESCAPED_UNICODE) ?: '',
                    ]));
                    foreach ($requestedAttributes as $aliases) {
                        $found = false;
                        foreach ($aliases as $alias) {
                            if (preg_match('/(?<![\pL\pN])' . preg_quote(mb_strtolower((string) $alias), '/') . '(?![\pL\pN])/iu', $searchable)) {
                                $found = true;
                                break;
                            }
                        }
                        if (!$found) return false;
                    }
                    return true;
                }) !== [];

                if ($matchingProducts === [] || !$attributeMatch) {
                    $availableLines = [];
                    foreach (array_slice($productsContext, 0, 5) as $product) {
                        if (!is_array($product)) continue;
                        $productName = trim((string) ($product['name'] ?? ''));
                        if ($productName === '') continue;
                        $productPrice = $product['price'] ?? null;
                        $availableLines[] = $productPrice !== null
                            ? $productName . ' — ' . $productPrice . ' ' . ($product['currency'] ?? 'SAR')
                            : $productName;
                    }
                    $reply = $detectedLanguage === 'arabic'
                        ? 'ما لقيت منتجًا أو خيارًا مطابقًا لطلبك في كتالوج المتجر. المنتجات المتاحة حاليًا:'
                            . ($availableLines !== [] ? "\n" . implode("\n", $availableLines) : '')
                        : 'I could not find an exact product or option match in this store catalog. Here are products currently listed:'
                            . ($availableLines !== [] ? "\n" . implode("\n", $availableLines) : '');
                    $this->sendSallaDeterministicReply($channel, $conversation, $reply, $detectedLanguage, 'salla_product_no_exact_match');
                    return;
                }
            }
        }

        // Respond directly to price objections so product disambiguation or
        // provider outages cannot ignore the objection or expose internal IDs.
        $isDiscountObjection = (bool) preg_match(
            '/\b(?:discount|cheaper|too\s+expensive|too\s+much|lower\s+price|price\s+is\s+high|any\s+offer)\b|(?:خصم|أرخص|ارخص|غالي|غالية|مكلف|السعر\s+مرتفع|فيه\s+عرض)/iu',
            (string) $message->content
        );
        if ($sallaChannel && $isDiscountObjection) {
            $reply = $detectedLanguage === 'arabic'
                ? 'فاهم إن السعر ممكن يكون أعلى من ميزانيتك. ما عندي خصم مؤكد من المتجر أقدر أوعدك به. تحب أساعدك تبحث عن خيار أقل سعرًا؟'
                : "I understand the price may be over your budget. I don't have a verified store discount to offer. Would you like help finding a lower-priced option?";
            $this->sendSallaDeterministicReply($channel, $conversation, $reply, $detectedLanguage, 'salla_discount_objection');
            return;
        }

        // Salla-exclusive turns intentionally omit unrelated business memory,
        // and this integration does not yet load delivery/payment policy
        // settings from Salla. Answer policy questions honestly instead of
        // letting the model fill the gap with generic industry assumptions.
        $isStorePolicyQuestion = (bool) preg_match(
            '/\b(?:delivery\s*time|shipping\s*time|how\s+long\s+(?:does\s+)?(?:delivery|shipping|it\s+take)|when\s+(?:will\s+)?(?:it|my\s+order)\s+arrive|payment\s+methods?|pay\s+by\s+card|accept\s+(?:cards?|cash\s+on\s+delivery)|return\s+policy|refund\s+policy|exchange\s+policy)\b|(?:كم\s+يوم|مدة\s+(?:التوصيل|الشحن)|متى\s+(?:يوصل|يصل)|طرق\s+الدفع|وسائل\s+الدفع|الدفع\s+ب(?:البطاقة|بطاقة)|تقبلون\s+بطاقات?|(?:هل|تقبلون).{0,20}الدفع\s+عند\s+الاستلام|سياسة\s+(?:الاسترجاع|الاستبدال)|استرجاع|استبدال)/iu',
            (string) $message->content
        );
        $isCheckoutOrPurchaseTurn = $isPlaceOrder || $isExplicitOrderConfirmationMessage || $incomingCheckoutDetails;
        if ($sallaChannel && $isStorePolicyQuestion && !$isCheckoutOrPurchaseTurn && !$isOrderStatus) {
            $policyReply = $detectedLanguage === 'arabic'
                ? 'ما عندي حاليًا معلومة مؤكدة من المتجر عن سياسة التوصيل أو الدفع أو الاسترجاع، وما أبغى أعطيك معلومة غير دقيقة. أقدر أساعدك في سؤال آخر عن المنتجات أو الطلب.'
                : "I don't have verified store information about delivery, payment, or returns right now, and I don't want to give you an inaccurate answer. I can still help with products or your order.";
            $this->sendSallaDeterministicReply($channel, $conversation, $policyReply, $detectedLanguage, 'salla_policy_not_configured');
            return;
        }

        // Step 4: Single AI Call with JSON Output
        try {
            $aiResult = AICapabilitiesService::callAIWithJSON($message->content, $context);
        } catch (\App\Exceptions\InsufficientAiCreditsException $exception) {
            Log::info('ProcessAutoReply: AI generation skipped because estimated reservation exceeds available credits', [
                'business_id' => $business->id,
                'conversation_id' => $conversation->id,
                'message_id' => $message->id,
                'available_credits' => $exception->balance['available'] ?? null,
                'required_credits' => $exception->balance['required'] ?? null,
            ]);
            $this->notifyInsufficientAiCredits($business, $conversation, $message);
            return;
        }
        $auditProvider = $aiResult['provider'] ?? ($context['ai_provider'] ?: config('services.ai.provider', 'gemini'));
        $auditModel = $aiResult['model'] ?? ($context['ai_model'] ?: ($auditProvider === 'groq'
            ? config('services.groq.model', 'openai/gpt-oss-120b')
            : config('services.gemini.model', 'gemini-2.5-flash')));

        if (!empty($aiResult['success'])) {
            try {
                \Illuminate\Support\Facades\Cache::forget('ai_reply_failures:conversation:' . $conversation->id);
            } catch (\Throwable $cacheException) {
                // A healthy AI reply should proceed even if the cache backend
                // is temporarily unavailable.
            }
        }

        if (!$aiResult['success']) {
            // Provider outages are transient service failures, not a reason to
            // transfer the customer. callAIWithJSON already retries and tries
            // configured fallback models; keep the reply recoverable if those
            // providers are rate-limited or unavailable.
            $aiFailureKey = 'ai_reply_failures:conversation:' . $conversation->id;
            $aiFailureCode = (string) ($aiResult['failure_code'] ?? data_get($aiResult, 'validation.reasons.0', 'ai_failure'));
            try {
                if ($aiFailureCode === 'provider_exception') {
                    \Illuminate\Support\Facades\Cache::forget($aiFailureKey);
                    $consecutiveAiFailures = 0;
                } else {
                    \Illuminate\Support\Facades\Cache::add($aiFailureKey, 0, now()->addMinutes(15));
                    $consecutiveAiFailures = (int) \Illuminate\Support\Facades\Cache::increment($aiFailureKey);
                    \Illuminate\Support\Facades\Cache::put($aiFailureKey, $consecutiveAiFailures, now()->addMinutes(15));
                }
            } catch (\Throwable $cacheException) {
                // Cache is only a guardrail; if unavailable, prefer the safe
                // non-escalating first-failure behavior.
                $consecutiveAiFailures = 1;
            }
            $shouldEscalateAiFailure = $aiFailureCode !== 'provider_exception' && $consecutiveAiFailures >= 3;

            Log::error('ProcessAutoReply: AI call failed', [
                'conversation_id' => $conversation->id,
                'message_id' => $message->id,
                'fallback_used' => true,
                'provider' => $auditProvider,
                'model' => $auditModel,
                'error_type' => isset($aiResult['error']) ? get_debug_type($aiResult['error']) : null,
                'error_code' => is_array($aiResult['error'] ?? null) ? ($aiResult['error']['code'] ?? null) : null,
                'failure_code' => $aiResult['failure_code'] ?? data_get($aiResult, 'validation.reasons.0'),
                'consecutive_failures' => $consecutiveAiFailures,
                'escalation_after_repeated_failure' => $shouldEscalateAiFailure,
                'confirmation_phrase' => (bool) $containsConfirmPhrase,
                'confirmation_state' => $checkoutState['confirmation_state'] ?? null,
                'valid_summary' => (bool) $hasValidOrderSummary,
                'checkout_complete' => (bool) ($fieldStatus['is_complete'] ?? false),
                'commerce_connection_id' => $commerceChannel?->id,
                'commerce_provider' => $commerceChannel?->type,
                'order_submission_reached' => false,
                'order_submission_skipped_reason' => $containsConfirmPhrase
                    ? 'AI failed before commerce order creation block'
                    : 'message was not classified as an explicit order confirmation',
            ]);

            $confirmedCheckoutReady = $containsConfirmPhrase
                && in_array($checkoutState['confirmation_state'] ?? null, ['confirmed', 'order_placed'], true)
                && $hasValidOrderSummary
                && !$isProductBrowseMessage
                && $commerceChannel
                && !empty($fieldStatus['is_complete'])
                && !empty($checkoutState['salla_product_id'])
                && !empty($checkoutState['customer_phone'] ?? $checkoutState['phone'] ?? '')
                && !empty($checkoutState['address'] ?? $checkoutState['customer_address'] ?? '')
                && !empty($checkoutState['full_name'] ?? $checkoutState['customer_name'] ?? '')
                && $autoOrderConfirmationEnabled;

            if ($confirmedCheckoutReady) {
                Log::warning('ProcessAutoReply: AI failed after valid confirmation; continuing deterministic order submission', [
                    'conversation_id' => $conversation->id,
                    'message_id' => $message->id,
                    'connection_id' => $commerceChannel->id,
                    'provider' => $commerceChannel->type,
                    'failure_code' => $aiResult['failure_code'] ?? data_get($aiResult, 'validation.reasons.0'),
                ]);

                if ($this->submitConfirmedCheckoutWithoutAi($conversation, $channel, $commerceChannel, $checkoutState, $detectedLanguage)) {
                    return;
                }

                Log::error('ProcessAutoReply: deterministic order submission after AI failure returned no order ID', [
                    'conversation_id' => $conversation->id,
                    'message_id' => $message->id,
                    'connection_id' => $commerceChannel->id,
                    'provider' => $commerceChannel->type,
                ]);
            }

            // A failed AI call must not turn a successful product lookup into
            // silence. Give browse requests a useful, data-backed reply from
            // the Salla results already fetched for this turn.
            $productBrowseFallback = null;
            if ($isProductAggregate && !empty($productsAggregateContext['items'])) {
                $lines = [];
                foreach ($productsAggregateContext['items'] as $item) {
                    $name = trim((string) ($item['name'] ?? 'Product'));
                    $price = $item['price'] ?? null;
                    $currency = $item['currency'] ?? 'SAR';
                    $lines[] = $price !== null
                        ? "• {$name} — {$price} {$currency}"
                        : "• {$name}";
                }
                $total = (int) ($productsAggregateContext['total_count'] ?? count($lines));
                $productBrowseFallback = "Here are the products I found ({$total} total):\n" . implode("\n", $lines);
                if (count($lines) < $total) {
                    $productBrowseFallback .= "\nWould you like to see more?";
                }
            }

            if ($productBrowseFallback) {
                // A catalog-backed answer succeeded for this turn, so it
                // breaks the consecutive AI-failure streak.
                $shouldEscalateAiFailure = false;
                try {
                    \Illuminate\Support\Facades\Cache::forget($aiFailureKey);
                } catch (\Throwable $cacheException) {
                    // Do not block the data-backed fallback reply.
                }
            }

            // Explain the recoverable issue in customer language without
            // exposing provider errors or pretending a human is already
            // handling it. Offer a concrete next step; hand off after repeats.
            if (!$productBrowseFallback && $shouldEscalateAiFailure) {
                $conversation->update([
                    'requires_human' => true,
                    'escalated_at' => now(),
                    'escalation_reason' => 'ai_failure_fallback'
                ]);
                app(\App\Services\HandoffTaskService::class)->openForConversation($conversation, 'ai_failure_fallback');
            }

            $failureExplanation = match ($aiFailureCode) {
                'provider_exception' => "I couldn't reach the assistant service just now, so I haven't completed that request. Please send it once more in a moment.",
                'malformed_json_object', 'invalid_json', 'raw_json_reply_field', 'response_validation_failed' => "I received an unreadable response while processing that request, so I haven't taken action. Please rephrase it or send the key details in one short message.",
                default => "I couldn't safely complete that request because the assistant couldn't process it. Please try again with the key details, and I'll continue from there.",
            };
            if ($shouldEscalateAiFailure) {
                $failureExplanation .= " I've also asked a team member to review it.";
            }
            if ($detectedLanguage === 'arabic') {
                $failureExplanation = match ($aiFailureCode) {
                    'provider_exception' => 'تعذر الاتصال بخدمة المساعد الآن، لذلك لم أُكمل طلبك. أرسل رسالتك مرة أخرى بعد قليل من فضلك.',
                    'malformed_json_object', 'invalid_json', 'raw_json_reply_field', 'response_validation_failed' => 'وصلني رد غير واضح أثناء معالجة طلبك، لذلك لم أنفذ أي إجراء. أعد صياغة طلبك أو أرسل التفاصيل الأساسية في رسالة قصيرة.',
                    default => 'لم أتمكن من معالجة طلبك بأمان. أرسل التفاصيل الأساسية مرة أخرى وسأتابع معك.',
                };
                if ($shouldEscalateAiFailure) {
                    $failureExplanation .= ' وطلبت من أحد أعضاء الفريق مراجعته.';
                }
            }

            // Product browsing can use the catalog data already loaded even
            // when the model is unavailable.
            $fallbackMessage = $productBrowseFallback
                ?? $failureExplanation;
            
            $replyMessage = Message::create([
                'conversation_id' => $message->conversation_id,
                'content' => $fallbackMessage,
                'direction' => 'outbound',
                'status' => 'auto',
                'is_ai' => (bool) $productBrowseFallback,
                'source' => $productBrowseFallback ? 'salla_product_browse' : 'fallback',
                'send_status' => 'pending',
            ]);

            if ($productBrowseFallback) {
                try {
                    app(\App\Services\AiReplyAuditService::class)->record($replyMessage, [
                        'commerce_connection_id' => $commerceChannel?->id,
                        'agent_version' => $bot?->updated_at?->toIso8601String(),
                        'knowledge_file_ids' => $knowledgeFileIds,
                        'knowledge_chunk_ids' => $knowledgeChunkIds,
                        'ai_provider' => $auditProvider,
                        'ai_model' => $auditModel,
                        'escalation_reason' => 'ai_failure_product_browse_fallback',
                    ]);
                } catch (\Throwable $e) {
                    Log::error('ProcessAutoReply: could not persist AI reply audit', [
                        'message_id' => $replyMessage->id,
                        'exception_type' => get_class($e),
                    ]);
                }
            }

            if ($channel->user_id) {
                broadcast(new \App\Events\MessageReceived($replyMessage, $conversation, $channel->user_id));
                
                if ($shouldEscalateAiFailure && !$productBrowseFallback) {
                    $this->notifyAIFailure($channel->user, $conversation, $aiFailureCode);
                }
            }
            $this->sendReply($channel, $conversation, $replyMessage);
            return;
        }

        // Use this same AI turn to extract explicit checkout spans. The
        // checkout service accepts only literal values found in the latest
        // inbound message and validates phone/email before persistence.
        $checkoutAiFieldsSaved = [];
        if (is_array($context['checkout_extraction'] ?? null) && is_array($aiResult['checkout_fields'] ?? null)) {
            $beforeAiExtraction = $updatedCheckoutState;
            $aiFields = $aiResult['checkout_fields'];
            $existingAliases = [
                'full_name' => ['full_name', 'customer_name'], 'phone' => ['phone', 'customer_phone'],
                'email' => ['email', 'customer_email'], 'address' => ['address', 'customer_address'],
                'postal_code' => ['postal_code'], 'building_number' => ['building_number'],
                'street_number' => ['street_number'],
                'block' => ['block'], 'district' => ['district'], 'short_address' => ['short_address'],
                'additional_number' => ['additional_number'],
            ];
            $correctionCue = (bool) preg_match('/\b(?:actually|correction|correct|updated|change|instead)\b|(?:تصحيح|الصحيح|عدّل|غيرت|بدل)/iu', (string) $message->content);
            foreach ($existingAliases as $field => $aliases) {
                $hadPreviousValue = false;
                foreach ($aliases as $alias) {
                    if (!empty($checkoutStateBeforeIncoming[$alias])) {
                        $hadPreviousValue = true;
                        break;
                    }
                }
                if ($hadPreviousValue && !$correctionCue) {
                    unset($aiFields[$field]);
                }
            }
            if (isset($aiFields['address'])
                && (!is_string($aiFields['address'])
                    || !$checkoutService->isAcceptableAddressValue($aiFields['address'], $addressWasRequested))) {
                unset($aiFields['address']);
            }
            $updatedCheckoutState = $checkoutService->mergeVerifiedAiFields(
                $updatedCheckoutState,
                $aiFields,
                (string) $message->content
            );
            foreach (['full_name', 'phone', 'email', 'address', 'postal_code', 'building_number', 'street_number', 'block', 'district', 'short_address', 'additional_number'] as $field) {
                if (($updatedCheckoutState[$field] ?? null) !== ($beforeAiExtraction[$field] ?? null)
                    && !empty($updatedCheckoutState[$field])) {
                    $checkoutAiFieldsSaved[] = $field;
                }
            }
            if ($checkoutAiFieldsSaved !== []) {
                $conversation->update(['checkout_state' => $updatedCheckoutState]);
                $fieldStatus = $checkoutService->computeFieldStatus(
                    $updatedCheckoutState,
                    $sallaCheckoutActive,
                    (array) ($updatedCheckoutState['salla_required_fields'] ?? [])
                );
                $updatedCheckoutState = $checkoutService->updateConfirmationState(
                    $updatedCheckoutState,
                    (string) $message->content,
                    (bool) ($fieldStatus['is_complete'] ?? false),
                    (bool) $hasValidOrderSummary
                );
                $conversation->update(['checkout_state' => $updatedCheckoutState]);
                $checkoutState = $updatedCheckoutState;
                $structuredState['customer'] = array_merge($structuredState['customer'] ?? [], [
                    'name' => $updatedCheckoutState['full_name'] ?? $updatedCheckoutState['customer_name'] ?? null,
                    'phone' => $updatedCheckoutState['phone'] ?? $updatedCheckoutState['customer_phone'] ?? null,
                    'address' => $updatedCheckoutState['address'] ?? $updatedCheckoutState['customer_address'] ?? null,
                ]);
                $structuredState['pending_action'] = $this->determinePendingAction($updatedCheckoutState, $isPlaceOrder, $fieldStatus);
                Log::info('ProcessAutoReply: AI checkout spans validated and stored', [
                    'conversation_id' => $conversation->id,
                    'message_id' => $message->id,
                    'saved_field_names' => $checkoutAiFieldsSaved,
                    'missing_field_names' => array_values($fieldStatus['missing_fields'] ?? []),
                ]);
            }
            $checkoutDataChangedThisTurn = $checkoutDataChangedThisTurn
                || $this->orderSummaryFingerprint($checkoutStateBeforeIncoming) !== $this->orderSummaryFingerprint($updatedCheckoutState);
        }

        // Step 5: Intelligent Escalation Decision
        //
        // ONLY escalate for:
        //   1. Customer explicitly requested human (customer_requested_human)
        //   2. Customer is making a serious complaint/problem (complaint, sensitive_issue)
        //   3. Business rule requires escalation (business_rule)
        //   4. AI confidence below configured threshold
        //   5. Keyword match from escalation_config
        //
        // DO NOT escalate for:
        //   - general questions (even if AI doesn't know the answer)
        //   - information_missing (AI should just say it doesn't know and ask if user wants human)

        $escalationReason    = $aiResult['escalation_reason'] ?? 'none';
        $needsEscalation     = $aiResult['needs_escalation'] ?? false;
        $intent              = $aiResult['intent'] ?? 'unknown';
        $confidenceScore     = $aiResult['confidence'] ?? 1.0;

        // The customer is answering a numbered Salla option prompt. Treat
        // that answer as checkout input even if the model calls "1" a generic
        // question or returns low confidence. The AI classification was
        // overwriting the checkout intent set before the AI call, which sent
        // valid size choices to the human handoff path.
        if ($isSallaOptionFollowUp) {
            $intent = 'place_order';
            $aiResult['intent'] = 'place_order';
            if (!in_array($escalationReason, ['customer_requested_human', 'complaint', 'sensitive_issue'], true)) {
                $needsEscalation = false;
                $aiResult['needs_escalation'] = false;
                $escalationReason = 'none';
                $aiResult['escalation_reason'] = 'none';
            }
        }

        if ($isProductBrowseMessage) {
            $intent = 'question';
            $aiResult['intent'] = 'question';
            if (preg_match('/\b(images?|photos?|pictures?|pics?)\b/i', $message->content)) {
                $aiResult['needs_images'] = true;
            }
        }

        // Deterministic order-status detection outranks a model label such as
        // `place_order`, especially while an unrelated checkout is held open.
        if ($isOrderStatus && !$isExplicitOrderConfirmationMessage) {
            $intent = 'order_status';
            $aiResult['intent'] = 'order_status';
        }

        if ($checkoutAiFieldsSaved !== [] && $intent === 'place_order' && empty($fieldStatus['is_complete'])) {
            $fieldLabels = $detectedLanguage === 'arabic' ? [
                'full_name' => 'الاسم الكامل', 'phone' => 'رقم الجوال', 'address' => 'عنوان التوصيل',
                'email' => 'البريد الإلكتروني', 'postal_code' => 'الرمز البريدي',
                'building_number' => 'رقم المبنى', 'street_number' => 'رقم الشارع', 'block' => 'الحي أو رقم الدور',
                'district' => 'الحي', 'short_address' => 'العنوان الوطني المختصر',
                'additional_number' => 'الرقم الإضافي', 'delivery_city' => 'المدينة',
            ] : [
                'full_name' => 'full name', 'phone' => 'phone number', 'address' => 'delivery address',
                'email' => 'email address', 'postal_code' => 'postal code',
                'building_number' => 'building number', 'street_number' => 'street number', 'block' => 'block or floor',
                'district' => 'district', 'short_address' => 'short national address',
                'additional_number' => 'additional address number', 'delivery_city' => 'delivery city',
            ];
            $stillMissing = array_values(array_filter(
                (array) ($fieldStatus['missing_fields'] ?? []),
                static fn ($field) => $field !== 'geo_coordinates'
            ));
            $labels = array_values(array_filter(array_map(static fn ($field) => $fieldLabels[$field] ?? null, $stillMissing)));
            if ($labels !== []) {
                $aiResult['reply'] = $detectedLanguage === 'arabic'
                    ? 'تمام، سجلت البيانات اللي بعتها. عشان أكمل الطلب، ابعتلي ' . implode('، ', $labels) . ' في رسالة واحدة.'
                    : 'Thanks, I saved the details you sent. To continue, please send ' . implode(', ', $labels) . ' together in one message.';
            }
        }

        // A customer confirming a pending order summary is not asking about a product.
$summaryConfirmTurn = ($checkoutState['confirmation_state'] ?? null) === 'confirmation_pending' && \App\Services\OrderCheckoutService::isExplicitOrderConfirmation((string) $message->content);
if (!$isProductBrowseMessage && $commerceChannel && !$summaryConfirmTurn) {
            if ($referenceResolution['resolution'] === 'ambiguous') {
                $options = [];
                foreach (array_values($referenceResolution['candidates']) as $index => $candidate) {
                    $options[] = ($index + 1) . '. ' . ($candidate['name'] ?? 'Product') . ' — '
                        . ($candidate['price'] ?? '?') . ' ' . ($candidate['currency'] ?? '');
                }
                if ($options) {
                    $aiResult['reply'] = $detectedLanguage === 'arabic'
                        ? "تقصد أي منتج؟ اختار رقم المنتج:\n" . implode("\n", $options)
                        : "Which product do you mean? Please choose one:\n" . implode("\n", $options);
                } else {
                    $aiResult['reply'] = $detectedLanguage === 'arabic'
                        ? 'أي منتج تقصد؟ أقدر أعرض لك المنتجات المتاحة.'
                        : 'Which product do you mean? I can show you the available products.';
                }
                $intent = 'question';
                $isPlaceOrder = false;
                $needsEscalation = false;
                $aiResult['intent'] = 'question';
                $aiResult['needs_escalation'] = false;
                $escalationReason = 'none';
            } elseif ($referenceResolution['resolution'] === 'resolved_recent' && $referenceIntent === 'place_order') {
                $productName = $referencedProduct['name'] ?? 'this product';
                $aiResult['reply'] = $detectedLanguage === 'arabic'
                    ? "تقصد {$productName}؟ أكد لي من فضلك قبل ما نبدأ الطلب."
                    : "Do you mean {$productName}? Please confirm before I start the order.";
                $intent = 'question';
                $isPlaceOrder = false;
                $aiResult['intent'] = 'question';
                $needsEscalation = false;
                $aiResult['needs_escalation'] = false;
                $escalationReason = 'none';
            } elseif ($referenceResolution['resolution'] === 'none' && $referenceResolution['is_pointer']) {
                $aiResult['reply'] = $detectedLanguage === 'arabic'
                    ? 'أي منتج تقصد؟ أقدر أعرض لك المنتجات المتاحة.'
                    : 'Which product do you mean? I can show you the available products.';
                $intent = 'question';
                $isPlaceOrder = false;
                $aiResult['intent'] = 'question';
                $needsEscalation = false;
                $aiResult['needs_escalation'] = false;
                $escalationReason = 'none';
            } elseif ($referenceResolution['resolution'] === 'resolved' && $referenceIntent !== null) {
                $intent = $referenceIntent;
                $aiResult['intent'] = $referenceIntent;
            }
        }

        // A Maps URL is a delivery-detail answer during an active checkout,
        // even if the model labels the bare link as an unclear question.
        $isCheckoutMapsLink = !$isProductBrowseMessage
            && !empty($updatedCheckoutState['salla_product_id'])
            && (bool) preg_match(
                '~https?://(?:maps\.app\.goo\.gl|goo\.gl/maps|(?:www\.)?google\.com/maps|maps\.google\.com/maps)[^\s<>\]\)]*~i',
                $message->content
            );
        if ($isCheckoutMapsLink) {
            $intent = 'place_order';
            $aiResult['intent'] = 'place_order';

            $missingLabels = $detectedLanguage === 'arabic' ? [
                'full_name' => 'الاسم الكامل',
                'phone' => 'رقم الهاتف',
                'address' => 'عنوان التوصيل الكامل',
                'email' => 'البريد الإلكتروني',
                'postal_code' => 'الرمز البريدي',
                'geo_coordinates' => 'عنوان التوصيل المكتوب كاملًا مع الشارع ورقم المبنى والحي والرمز البريدي',
                'building_number' => 'رقم المبنى',
                'street_number' => 'رقم الشارع',
                'address_line' => 'اسم الشارع وعنوان التوصيل',
                'delivery_city' => 'مدينة توصيل يدعمها المتجر',
                'short_address' => 'العنوان الوطني المختصر',
                'additional_number' => 'الرقم الإضافي',
            ] : [
                'full_name' => 'full name',
                'phone' => 'phone number',
                'address' => 'complete delivery address',
                'email' => 'email address',
                'postal_code' => 'postal / ZIP code',
                'geo_coordinates' => 'complete written delivery address with street, building number, district, and postal code',
                'building_number' => 'building or house number',
                'street_number' => 'street number',
                'address_line' => 'street and delivery address line',
                'delivery_city' => 'a delivery city supported by the store',
                'short_address' => 'short / national address code',
                'additional_number' => 'additional address number',
            ];
            $missingDetails = array_map(
                fn ($field) => $missingLabels[$field] ?? str_replace('_', ' ', $field),
                $fieldStatus['missing_fields']
            );
            $hasCoordinates = !empty($updatedCheckoutState['geo_coordinates']);

            if ($missingDetails) {
                if ($detectedLanguage === 'arabic') {
                    $locationNote = 'شكرًا على تفاصيل التوصيل. ';
                    $aiResponse = $locationNote . 'لإتمام الطلب، أرسل البيانات الناقصة كلها في رسالة واحدة: ' . implode('، ', $missingDetails) . '.';
                } else {
                    $locationNote = 'Thanks for the delivery details. ';
                    $aiResponse = $locationNote . 'To complete your order, please send all remaining details in one message: ' . implode(', ', $missingDetails) . '.';
                }
            } else {
                $aiResponse = $detectedLanguage === 'arabic'
                    ? 'تم استلام موقع التوصيل. أرسل «تأكيد الطلب» عندما تكون جاهزًا لإتمام الطلب.'
                    : 'Thanks, I received your delivery location. Reply “confirm order” when you are ready to place the order.';
            }

            if (!$hasCoordinates) {
                Log::info('ProcessAutoReply: Maps link received during checkout but coordinates remain unavailable', [
                    'conversation_id' => $conversation->id,
                ]);
            }
        }

        // A missing OAuth grant can leave this conversation without a connected
        // Salla store. Never claim a confirmed checkout was submitted in that case.
        $orderCreationGateFailures = [];
        if (!$containsConfirmPhrase) $orderCreationGateFailures[] = 'no_confirmation_phrase';
        if (!$hasValidOrderSummary) $orderCreationGateFailures[] = 'no_valid_current_summary';
        if (!in_array($checkoutState['confirmation_state'] ?? null, ['confirmed', 'order_placed'], true)) $orderCreationGateFailures[] = 'confirmation_state_not_confirmed';
        if ($isProductBrowseMessage) $orderCreationGateFailures[] = 'product_browse_message';
        if (!$commerceChannel) $orderCreationGateFailures[] = 'commerce_connection_unresolved';
        if (empty($fieldStatus['is_complete'])) $orderCreationGateFailures[] = 'checkout_fields_incomplete';
        if (empty($checkoutState['salla_product_id'])) $orderCreationGateFailures[] = 'product_id_missing';
        if (empty($checkoutState['customer_phone'] ?? $checkoutState['phone'] ?? '')) $orderCreationGateFailures[] = 'phone_missing';
        if (empty($checkoutState['address'] ?? $checkoutState['customer_address'] ?? '')) $orderCreationGateFailures[] = 'address_missing';
        if (empty($checkoutState['full_name'] ?? $checkoutState['customer_name'] ?? '')) $orderCreationGateFailures[] = 'name_missing';
        if (!$autoOrderConfirmationEnabled) $orderCreationGateFailures[] = 'automatic_order_submission_disabled';
        if ($containsConfirmPhrase || in_array($checkoutState['confirmation_state'] ?? null, ['confirmed', 'order_placed'], true)) {
            Log::info('ProcessAutoReply: commerce order creation gate result', [
                'conversation_id' => $conversation->id,
                'message_id' => $message->id,
                'provider' => $commerceChannel?->type,
                'connection_id' => $commerceChannel?->id,
                'passed' => empty($orderCreationGateFailures),
                'failed_conditions' => $orderCreationGateFailures,
                'confirmation_state' => $checkoutState['confirmation_state'] ?? null,
                'confirmation_phrase' => (bool) $containsConfirmPhrase,
                'checkout_complete' => (bool) ($fieldStatus['is_complete'] ?? false),
            ]);
        }
        if (
            $containsConfirmPhrase
            && $hasValidOrderSummary
            && in_array($checkoutState['confirmation_state'] ?? null, ['confirmed', 'order_placed'], true)
            && !empty($fieldStatus['is_complete'])
            && !$commerceChannel
            && !empty($checkoutState['salla_product_id'])
        ) {
            $orderCreationFailedReason = 'salla_store_not_connected';
            Log::warning('ProcessAutoReply: confirmed checkout has no connected Salla store; blocking order success claim', [
                'conversation_id' => $conversation->id,
                'product_id' => $checkoutState['salla_product_id'],
            ]);
        }

        // Reasons that should ALWAYS trigger escalation
        $hardEscalationReasons = [
            'customer_requested_human',
            'complaint',
            'sensitive_issue',
            'business_rule',
        ];

        // Decide whether to escalate
        $shouldEscalate   = false;
        $decisionReason   = 'auto_reply_default';

        // Check 1: AI hard escalation reasons
        if ($needsEscalation && in_array($escalationReason, $hardEscalationReasons)) {
            $shouldEscalate = true;
            $decisionReason = "ai_hard_escalation: {$escalationReason}";
        }

        // Check 2: Confidence threshold enforcement
        $effectiveThreshold = $effectiveConfidenceThreshold ?? 0.70;
        if (!$shouldEscalate && $confidenceScore < $effectiveThreshold) {
            $shouldEscalate = true;
            $decisionReason = 'ai_confidence_below_threshold';
            Log::info('ProcessAutoReply: AI confidence below threshold', [
                'conversation_id' => $conversation->id,
                'confidence' => $confidenceScore,
                'threshold' => $effectiveThreshold,
            ]);
        }

        // Check 3: Keyword-based escalation from escalation_config
        if (!$shouldEscalate && !empty($effectiveEscalationConfig['keywords']) && is_array($effectiveEscalationConfig['keywords'])) {
            $messageContent = mb_strtolower($message->content);
            foreach ($effectiveEscalationConfig['keywords'] as $keyword) {
                if (str_contains($messageContent, mb_strtolower($keyword))) {
                    $shouldEscalate = true;
                    $decisionReason = "escalation_keyword_match: {$keyword}";
                    Log::info('ProcessAutoReply: Escalation keyword matched', [
                        'conversation_id' => $conversation->id,
                        'keyword' => $keyword,
                    ]);
                    break;
                }
            }
        }

        // Check 4: Intent-based escalation from escalation_config
        if (!$shouldEscalate && !empty($effectiveEscalationConfig['intents']) && is_array($effectiveEscalationConfig['intents'])) {
            $intentLower = mb_strtolower($intent);
            foreach ($effectiveEscalationConfig['intents'] as $escalationIntent) {
                if ($intentLower === mb_strtolower($escalationIntent)) {
                    $shouldEscalate = true;
                    $decisionReason = "escalation_intent_match: {$intent}";
                    Log::info('ProcessAutoReply: Escalation intent matched', [
                        'conversation_id' => $conversation->id,
                        'intent' => $intent,
                    ]);
                    break;
                }
            }
        }

        // A live order-collection conversation should stay in the checkout
        // flow while the assistant asks for missing fields or handles an
        // explicit confirmation. Generic confidence/intent escalation here
        // disables AI and strands the customer mid-order. Keep explicit human
        // requests, complaints, sensitive issues, and business rules intact.
        $checkoutHardEscalations = ['customer_requested_human', 'complaint', 'sensitive_issue', 'business_rule'];
        if (
            $shouldEscalate
            && ($intent === 'place_order' || ($isOrderStatus && $sallaChannel))
            && $commerceChannel
            && !in_array($escalationReason, $checkoutHardEscalations, true)
        ) {
            $shouldEscalate = false;
            $decisionReason = $isOrderStatus ? 'salla_order_status_lookup' : 'active_checkout_collecting_or_confirming';
        }

        if ($isProductBrowseMessage) {
            $shouldEscalate = false;
            $decisionReason = 'product_browse_auto_reply';
        }

        // Log full AI decision for diagnostics
        Log::info('ProcessAutoReply: AI decision', [
            'conversation_id'  => $conversation->id,
            'intent'           => $intent,
            'confidence'       => round($confidenceScore * 100, 1),
            'threshold'        => $effectiveThreshold,
            'needs_escalation' => $needsEscalation,
            'escalation_reason' => $escalationReason,
        ]);

        Log::info('ProcessAutoReply: final decision', [
            'conversation_id' => $conversation->id,
            'decision'        => $shouldEscalate ? 'ESCALATE' : 'AUTO_REPLY',
            'decision_reason' => $decisionReason,
        ]);

        if ($shouldEscalate) {
            // Update conversation with full escalation state
            $conversation->update([
                'requires_human'    => true,
                'ai_enabled'        => false,
                'status'            => 'pending_human',
                'escalated_at'      => now(),
                'escalation_reason' => "{$decisionReason} (intent={$intent}, confidence=" . round($confidenceScore, 2) . ")",
            ]);

            app(\App\Services\HandoffTaskService::class)->openForConversation($conversation, $decisionReason);

            // Halt/Pause active sequences for this conversation
            try {
                if (class_exists(\App\Services\SequenceEnrollmentService::class)) {
                    $enrollmentService = app(\App\Services\SequenceEnrollmentService::class);
                    $enrollmentService->stopEnrollmentsForConversation($conversation, 'conversation_escalated');
                    Log::info('ProcessAutoReply: stopped active sequences on escalation', [
                        'conversation_id' => $conversation->id,
                    ]);
                }
            } catch (\Exception $seqEx) {
                Log::warning('ProcessAutoReply: failed to stop sequences on escalation', [
                    'conversation_id' => $conversation->id,
                    'exception_type' => get_class($seqEx),
                ]);
            }

            // Use custom fallback message if configured
            $escalationMessage = !empty($effectiveEscalationConfig['fallback_message'])
                ? $effectiveEscalationConfig['fallback_message']
                : "Sure ðŸ‘ I'm connecting you with a team member now. Please wait a moment.";

            $replyMessage = Message::create([
                'conversation_id' => $message->conversation_id,
                'content'         => $escalationMessage,
                'direction'       => 'outbound',
                'status'          => 'auto',
                'is_ai'           => false,
                'source'          => 'escalation',
                'send_status'     => 'pending',
            ]);

            if ($channel->user_id) {
                broadcast(new \App\Events\MessageReceived($replyMessage, $conversation, $channel->user_id));
            }
            $this->sendReply($channel, $conversation, $replyMessage);
            return;
        }

        // Step 6: Send AI Response
        $aiResponse = $aiResult['reply'];
        if ($shopifyVariantChosenThisMessage) {
            // A variant choice is a checkout answer even if the model calls it
            // a generic question. Send a fresh deterministic summary below.
            $intent = 'place_order';
        }

        // A complete order summary must be shown for one turn before a
        // customer's confirmation can authorize an external order. Do not let
        // an AI success claim bypass that deterministic checkout state.
        if (
            $intent === 'place_order'
            && $containsConfirmPhrase
            && !empty($fieldStatus['is_complete'])
            && $autoOrderConfirmationEnabled
            && !$isOrderStatus
            && !in_array($checkoutState['confirmation_state'] ?? null, ['confirmed', 'order_placed'], true)
        ) {
            $aiResponse = $detectedLanguage === 'arabic'
                ? 'هذه تفاصيل طلبك جاهزة. راجعها، ثم أرسل «تأكيد الطلب» لإتمامه.'
                : 'Your order details are ready. Please review the summary, then reply “confirm order” to place it.';
        }

        $shouldPresentOrderSummary = !$isOrderStatus
            && $intent === 'place_order'
            && (
                $confirmationStateBeforeUpdate !== 'confirmation_pending'
                || $checkoutDataChangedThisTurn
                || ($containsConfirmPhrase && !$hasValidOrderSummary && $autoOrderConfirmationEnabled)
            );
        $isOrderSummaryMessage = $shouldPresentOrderSummary
            && !empty($fieldStatus['is_complete'])
            && !$shopifyVariantSelectionRequired
            && !$sallaOptionSelectionRequired
            && ($checkoutState['confirmation_state'] ?? null) === 'confirmation_pending';
        if ($sallaOptionChosenThisMessage && !empty($fieldStatus['is_complete']) && !$sallaOptionSelectionRequired) {
            $isOrderSummaryMessage = true;
        }
        if ($isOrderSummaryMessage) {
            $aiResponse = $this->buildOrderConfirmationSummary($checkoutState, $commerceChannel);
        }

        // Ask for the product option first when the customer wants to order, so the option is
        // settled before name, phone and address are requested (not after them).
        $variantOrderIntent = $intent === 'place_order'
            || \App\Services\ShopifyOrderIntent::wantsToOrder((string) $message->content)
            || ($checkoutState['confirmation_state'] ?? null) === 'selecting_variant';
        if ($shopifyVariantSelectionRequired && $variantOrderIntent && !$isProductBrowseMessage) {
            $options = [];
            foreach ($shopifyVariants as $index => $variant) {
                $label = trim((string) ($variant['title'] ?? 'Variant'));
                if (isset($variant['price']) && $variant['price'] !== '') {
                    $label .= ' — ' . $variant['price'] . ' ' . ($checkoutState['product_currency'] ?? '');
                }
                $options[] = ($index + 1) . '. ' . $label;
            }
            if ($shopifyVariantDataUnavailable) {
                $aiResponse = "I can't verify this product's available options right now. A team member will help complete your order.";
                $conversation->update([
                    'requires_human' => true,
                    'status' => 'pending_human',
                    'escalated_at' => now(),
                    'escalation_reason' => 'shopify_variant_data_unavailable',
                ]);
                app(\App\Services\HandoffTaskService::class)->openForConversation(
                    $conversation,
                    'shopify_variant_data_unavailable',
                    'high'
                );
            } else {
                // When details are already saved, say so: the option is the only thing left before the summary.
$intro = !empty($fieldStatus['is_complete'])
    ? "Your details are saved. To show your order summary, which product option would you like? Reply with its number or exact name:\n"
    : "Which product option would you like? Reply with its number or exact name, then I'll ask for your name, phone and address:\n";
$aiResponse = $intro
                    . implode("\n", $options);
            }
        }

        if ($sallaOptionSelectionRequired
            && ($variantOrderIntent || ($checkoutState['confirmation_state'] ?? null) === 'selecting_salla_option')
            && !$isProductBrowseMessage) {
            $option = $sallaOptionPrompt;
            $values = $option['values'] ?? [];
            if ($option && $values !== []) {
                // ── Rule 1: store offered options in checkout_state so the matcher
                // has index↔label↔id mapping across turns without re-fetching. ──
                $offeredMap = [];
                foreach ($values as $vi => $value) {
                    $offeredMap[] = [
                        'index' => $vi + 1,
                        'id'    => (string) $value['id'],
                        'name'  => (string) $value['name'],
                    ];
                }
                $updatedCheckoutState['salla_offered_options'][$option['id']] = $offeredMap;

                // ── Rules 4 & 6: detect ambiguity vs. repeated failed match ─────
                $ambiguousIds    = $updatedCheckoutState['salla_option_ambiguous_ids'] ?? [];
                $failedAttempts  = (int) ($updatedCheckoutState['salla_option_failed_attempts'] ?? 0);

                if ($ambiguousIds !== [] && $failedAttempts === 0) {
                    // Rule 4: Multiple options matched — show only the ambiguous subset.
                    $filteredValues  = array_values(array_filter(
                        $values,
                        static fn ($v) => in_array((string) $v['id'], $ambiguousIds, true)
                    ));
                    $choices = [];
                    foreach ($filteredValues as $fi => $fv) {
                        // Keep original index numbers so customer can still use them.
                        $origIndex = array_search($fv, $values, true);
                        $choices[] = (($origIndex !== false ? $origIndex : $fi) + 1) . '. ' . $fv['name'];
                    }
                    $aiResponse = $detectedLanguage === 'arabic'
                        ? 'تعددت الخيارات المطابقة. اختر من هذه:' . "\n" . implode("\n", $choices)
                        : 'A few options matched. Which one did you mean?' . "\n" . implode("\n", $choices);
                    unset($updatedCheckoutState['salla_option_ambiguous_ids']);
                } else {
                    // Rule 6: on second failed match add an example hint.
                    $updatedCheckoutState['salla_option_failed_attempts'] = $failedAttempts + 1;

                    $choices = [];
                    foreach ($values as $vi => $value) {
                        $choices[] = ($vi + 1) . '. ' . $value['name'];
                    }
                    $optionNameLower = mb_strtolower((string) $option['name']);
                    $exampleValue    = $values[0]['name'] ?? '1';
                    $exampleIndex    = 1;

                    if ($failedAttempts === 0) {
                        // First time asking.
                        $aiResponse = $detectedLanguage === 'arabic'
                            ? 'اختار ' . $optionNameLower . ' المناسب لك، واكتب رقم الاختيار أو اسمه:' . "\n" . implode("\n", $choices)
                            : 'Which ' . $optionNameLower . ' would you like? Reply with its number or exact name:' . "\n" . implode("\n", $choices);
                    } elseif ($failedAttempts === 1) {
                        // Rule 6: second failed attempt — show hint with example.
                        $aiResponse = $detectedLanguage === 'arabic'
                            ? 'لم أتمكن من فهم الاختيار. اكتب رقم الخيار (مثل: ' . $exampleIndex . ') أو اسمه (مثل: ' . $exampleValue . '):' . "\n" . implode("\n", $choices)
                            : 'I couldn\'t match that. Type a number (e.g. ' . $exampleIndex . ') or the exact name (e.g. ' . $exampleValue . '):' . "\n" . implode("\n", $choices);
                    } else {
                        // Rule 6: third+ failed attempt — short re-list only.
                        $aiResponse = $detectedLanguage === 'arabic'
                            ? 'اختر رقماً:' . "\n" . implode("\n", $choices)
                            : 'Please choose by number:' . "\n" . implode("\n", $choices);
                    }
                }
            }
        }

        if ($sallaCheckoutActive && !$isOrderStatus && $addressGeocodingStatus === 'country_unknown') {
            $aiResponse = $detectedLanguage === 'arabic'
                ? 'من فضلك اذكر الدولة والمدينة التي سيتم التوصيل إليها حتى أتمكن من متابعة الطلب.'
                : 'Please tell me the country and city for delivery so I can continue with the order.';
        }

        // ── IMAGE SEND VERIFICATION (fixes: bot claiming "here are the photos"
        // while sending zero actual media) ────────────────────────────────────
        // The AI decides needs_images and writes "here are the photos" language
        // into $aiResponse BEFORE any image has actually been sent. We now
        // attempt the real sends first and only let that claim reach the
        // customer if at least one image genuinely went through.
        // CRITICAL ISSUE — REPLY-TO-PRODUCT CONTEXT: send full product items
        // (not just bare URLs) so each successfully-sent image can be persisted
        // as a ProductMessageMap row — the deterministic evidence a later reply
        // ("I want this one") resolves back to the exact product.
        $productImageItems = [];
        if (!empty($aiResult['needs_images']) && !empty($productsAggregateContext['items'])) {
            $productImageItems = array_values(array_filter(
                $productsAggregateContext['items'],
                fn($item) => !empty($item['image_url'])
            ));
            // Cap at 5 images to avoid spam
            $productImageItems = array_slice($productImageItems, 0, 5);
        }

        $imagesActuallySent = 0;
        if (!empty($productImageItems)) {
            $imagesActuallySent = $this->sendImagesToCustomer($channel, $conversation, $productImageItems, $commerceChannel);
            Log::info('ProcessAutoReply: image send attempt result', [
                'conversation_id' => $conversation->id,
                'attempted'       => count($productImageItems),
                'sent'            => $imagesActuallySent,
            ]);
        }

        if (!empty($aiResult['needs_images']) && $imagesActuallySent === 0) {
            Log::warning('ProcessAutoReply: AI intended to claim images were sent but zero actually sent -- overriding reply text to stay honest', [
                'conversation_id'  => $conversation->id,
                'candidate_images' => count($productImageItems),
            ]);
            $aiResponse = $detectedLanguage === 'arabic'
                ? "عذرًا، لا أستطيع إرسال صور المنتج الآن. سأقوم بتوصيلك بأحد أفراد الفريق ليرسلها لك مباشرة 🙏"
                : "I'm sorry, I'm not able to send product photos right now. Let me connect you with a team member who can send them directly ðŸ™";
        }

        if ($orderCreationFailedReason === 'salla_store_not_connected') {
            Log::warning('ProcessAutoReply: blocking order confirmation because no connected Salla store is available', [
                'conversation_id' => $conversation->id,
            ]);
            $aiResponse = "I'm sorry, your order was not submitted because the store connection needs attention. A team member will help you complete it.";
        }

        $checkoutConfirmedForSubmission = $containsConfirmPhrase
            && in_array($checkoutState['confirmation_state'] ?? null, ['confirmed', 'order_placed'], true)
            && !$isProductBrowseMessage
            && $commerceChannel
            && !empty($fieldStatus['is_complete'])
            && !empty($checkoutState['salla_product_id'])
            && !empty($checkoutState['customer_phone'] ?? $checkoutState['phone'] ?? '')
            && !empty($checkoutState['address'] ?? $checkoutState['customer_address'] ?? '')
            && !empty($checkoutState['full_name'] ?? $checkoutState['customer_name'] ?? '');

        if ($checkoutConfirmedForSubmission && !$autoOrderConfirmationEnabled) {
            $orderCreationFailedReason = 'order_confirmation_disabled';
            $aiResponse = $detectedLanguage === 'arabic'
                ? 'تم حفظ تفاصيل الطلب، لكن تأكيد الطلب التلقائي متوقف. سيتابع معك أحد أعضاء الفريق لإتمامه.'
                : 'I’ve saved your order details, but automatic order submission is turned off. A team member will follow up to complete it.';
            app(\App\Services\HandoffTaskService::class)->openForConversation(
                $conversation,
                'order_confirmation_disabled',
                'normal'
            );
            $conversation->update([
                'requires_human' => true,
                'status' => 'pending_human',
                'escalated_at' => now(),
                'escalation_reason' => 'order_confirmation_disabled',
            ]);
            $aiResponse = $detectedLanguage === 'arabic'
                ? 'تم حفظ تفاصيل طلبك، لكن لا يمكنني تقديم الطلب تلقائياً الآن. سيتواصل معك أحد أعضاء الفريق لتأكيده.'
                : "I've saved your order details, but I can't place the order automatically right now. A team member will contact you to confirm it.";
            Log::info('Order confirmation disabled', [
                'conversation_id' => $conversation->id,
                'channel_id' => $channel->id,
                'business_id' => $conversation->business_id,
            ]);
        }

        // STRICT GUARDRAIL: Prevent AI from hallucinating order confirmation if order creation failed
        if (in_array($orderCreationFailedReason, ['address_city_unresolved', 'salla_store_not_connected'], true)) {
            $lowerAiResponse = mb_strtolower($aiResponse);
            $hallucinationKeywords = ['placed', 'confirmed', 'تم تأكيد', 'تم طلب', 'successfully', 'will be placed'];
            $hallucinated = false;
            foreach ($hallucinationKeywords as $keyword) {
                if (str_contains($lowerAiResponse, $keyword)) {
                    $hallucinated = true;
                    break;
                }
            }

            if ($hallucinated) {
                Log::warning('ProcessAutoReply: AI hallucinated order confirmation despite order creation failure -- overriding reply text to stay honest', [
                    'conversation_id' => $conversation->id,
                ]);
                
                $aiResponse = $detectedLanguage === 'arabic'
                    ? "عذراً، لم أتمكن من تأكيد الطلب لأن مدينتك غير موجودة في نظام الشحن الخاص بنا. هل يمكنك توضيح اسم مدينتك والحي بدقة حتى أتمكن من إرسال الطلب؟ 🙏"
                    : "I'm sorry, I couldn't confirm your order because your city name wasn't recognized by our shipping system. Could you please reply with your exact city and neighborhood name so I can place it? ðŸ™";
            }
        }

        // Auto-tag conversation based on AI-detected intent
        if ($aiResult['intent'] !== 'unknown') {
            \App\Models\ConversationTag::firstOrCreate(
                ['conversation_id' => $conversation->id, 'tag' => $aiResult['intent']],
                ['intent' => $aiResult['intent'], 'confidence' => $aiResult['confidence'] * 100]
            );
        }

        // Detect Arabic dialect for training purposes
        $detectedDialect = ArabicDialectService::detectDialect($message->content);

        // Persist intent + language + normalized confidence so the training
        // dashboard can compute real per-message statistics. Normalization
        // keeps confidence on the app's canonical 0–1 scale regardless of what
        // the AI model returns (some providers return 0–100).
        $replyMessage = Message::create([
            'conversation_id' => $message->conversation_id,
            'content' => $aiResponse,
            'direction' => 'outbound',
            'status' => 'auto',
            'is_ai' => true,
            'source' => 'ai',
            'send_status' => 'pending',
            'metadata' => $isOrderSummaryMessage
                ? ['order_summary' => true]
                : (($referenceResolution['resolution'] === 'resolved_recent' && $referenceIntent === 'place_order')
                    ? ['pending_product_confirmation' => [
                        'commerce_channel_id' => $referencedProduct['commerce_channel_id'] ?? null,
                        'commerce_external_id' => $referencedProduct['commerce_external_id'] ?? null,
                    ]]
                    : (($referenceResolution['resolution'] === 'ambiguous' && !empty($referenceResolution['candidates']))
                        // Pin the exact numbered list shown to the customer, in order, so a bare
                        // "1" reply next turn maps deterministically to THIS list — never a
                        // freshly re-queried "recent products" set and never an AI guess.
                        ? ['pending_product_choices' => array_values($referenceResolution['candidates'])]
                        : null)),
            'confidence_score' => self::normalizeConfidenceScore($aiResult['confidence'] ?? null),
            'detected_dialect' => in_array($detectedDialect, ['egyptian', 'gulf', 'msa', 'mixed']) ? $detectedDialect : null,
            'intent' => $intent,
            'detected_language' => $detectedLanguage,
        ]);

        try {
            app(\App\Services\AiReplyAuditService::class)->record($replyMessage, [
                'commerce_connection_id' => $commerceChannel?->id,
                'agent_version' => $bot?->updated_at?->toIso8601String(),
                'knowledge_file_ids' => $knowledgeFileIds,
                'knowledge_chunk_ids' => $knowledgeChunkIds,
                'prompt_version' => config('services.ai.prompt_version', 'v1'),
                'ai_provider' => $auditProvider,
                'ai_model' => $auditModel,
                'escalation_decision' => $shouldEscalate,
                'escalation_reason' => $decisionReason,
            ]);
        } catch (\Throwable $e) {
            Log::error('ProcessAutoReply: could not persist AI reply audit', [
                'message_id' => $replyMessage->id,
                'exception_type' => get_class($e),
            ]);
        }

        Log::info('ProcessAutoReply: AI reply saved', ['message_id' => $replyMessage->id]);

        // ── REAL SALLA ORDER CREATION ────────────────────────────────────────────
        // The AI generated a "your order will be placed" reply, but we must
        // ACTUALLY call Salla here and only confirm to the customer if Salla
        // returns a real order ID. If Salla fails, we override the reply with
        // an honest failure message and escalate to a human agent.
        //
        // Trigger only after the customer explicitly confirms a valid summary,
        // the backend has resolved an active commerce connection, and the
        // checkout state contains the scoped product and required customer data.
        // The deterministic state is authoritative; the LLM intent label and
        // reply wording cannot veto a valid confirmation.
        //
        // This block runs BEFORE the checkout-state-persistence block so that
        // when the order succeeds we persist its real ID; repeated failures
        // clear the checkout state and escalate after the second attempt.
        if (
            $containsConfirmPhrase
            && $hasValidOrderSummary
            && in_array($checkoutState['confirmation_state'] ?? null, ['confirmed', 'order_placed'], true)
            && !$isProductBrowseMessage
            && $commerceChannel
            && !empty($fieldStatus['is_complete'])
            && !empty($checkoutState['salla_product_id'])
            && !empty($checkoutState['customer_phone'] ?? $checkoutState['phone'] ?? '')
            && !empty($checkoutState['address'] ?? $checkoutState['customer_address'] ?? '')
            && !empty($checkoutState['full_name'] ?? $checkoutState['customer_name'] ?? '')
            && ($commerceChannel->type !== 'salla' || !empty($checkoutState['salla_shipping_quote']))
            && $autoOrderConfirmationEnabled
        ) {
            Log::info('ProcessAutoReply: triggering real commerce order creation after validated confirmation', [
                'conversation_id' => $conversation->id,
                'product_id'      => $checkoutState['salla_product_id'],
                'customer_phone_present' => !empty($checkoutState['customer_phone'] ?? $checkoutState['phone'] ?? ''),
            ]);

                // Normalise checkout_state keys to what SallaService expects
                $primaryLineItem = is_array($checkoutState['salla_line_items'][0] ?? null) ? $checkoutState['salla_line_items'][0] : [];
                $primaryProductId = $checkoutState['salla_product_id']
                    ?? $primaryLineItem['salla_product_id']
                    ?? $primaryLineItem['product_id']
                    ?? null;
                $orderInput = [
                    'salla_product_id' => $primaryProductId,
                    'commerce_external_id' => $checkoutState['commerce_external_id'] ?? $primaryProductId,
                    'commerce_channel_id' => $commerceChannel->id,
                    'product_name'     => $checkoutState['product_name'] ?? $primaryLineItem['product_name'] ?? $primaryLineItem['name'] ?? null,
                    'product_price'    => $checkoutState['product_price']    ?? 0,
                    'commerce_variant_id' => $checkoutState['commerce_variant_id'] ?? null,
                    'commerce_variant_title' => $checkoutState['commerce_variant_title'] ?? null,
                    'salla_options' => $checkoutState['salla_options'] ?? [],
                    'salla_option_titles' => $checkoutState['salla_option_titles'] ?? [],
                    'salla_line_items' => $checkoutState['salla_line_items'] ?? [],
                    'full_name'        => $checkoutState['full_name']         ?? $checkoutState['customer_name']    ?? null,
                    'phone'            => $checkoutState['customer_phone']    ?? $checkoutState['phone']             ?? '',
                    'address'          => $checkoutState['address']           ?? $checkoutState['customer_address'] ?? '',
                    'email'            => $checkoutState['email']              ?? $checkoutState['customer_email'] ?? null,
                    'delivery_city'    => $checkoutState['delivery_city']       ?? null,
                    'salla_shipping_quote' => $checkoutState['salla_shipping_quote'] ?? null,
                    'postal_code'      => $checkoutState['postal_code']         ?? null,
                    'geo_coordinates'  => $checkoutState['geo_coordinates']     ?? null,
                    'building_number'  => $checkoutState['building_number']     ?? null,
                    'street_number'    => $checkoutState['street_number']       ?? null,
                    'address_line'     => $checkoutState['address_line']        ?? null,
                    'block'            => $checkoutState['block']               ?? null,
                    'district'         => $checkoutState['district']            ?? null,
                    'short_address'    => $checkoutState['short_address']       ?? null,
                    'additional_number'=> $checkoutState['additional_number']   ?? null,
                ];

                $orderData = $this->createRealExternalOrder($conversation, $orderInput, $channel);

                if (($orderData['status'] ?? null) === 'already_completed') {
                    $existingReference = trim((string) ($orderData['existing_order_number'] ?? ''));
                    $aiResponse = $detectedLanguage === 'arabic'
                        ? 'هذا الطلب مسجل لدينا بالفعل' . ($existingReference !== '' ? " بالرقم #{$existingReference}" : '') . '، لذلك لم أنشئ طلبًا آخر.'
                        : 'This checkout is already recorded' . ($existingReference !== '' ? " as order #{$existingReference}" : '') . ', so I did not create another order.';
                } elseif ($orderData && !empty($orderData['order_id'])) {
                    // ✅ SUCCESS — override the AI reply with the real order confirmation
                    $realOrderId  = $orderData['order_id'];
                    $realOrderNumber = $orderData['order_number'] ?? $realOrderId;
                    $customerName = $orderInput['full_name'] ?? '';
                    $productName  = $orderInput['product_name'] ?? '';

                    if ($detectedLanguage === 'arabic') {
                        $aiResponse = "✅ تم تأكيد طلبك بنجاح!" .
                            (!empty($customerName) ? " شكراً {$customerName}" : '') . "\n" .
                            (!empty($productName)  ? "المنتج: {$productName}\n" : '') .
                            "رقم الطلب: #{$realOrderNumber}\n" .
                            "سيتم التواصل معك قريباً لتأكيد التفاصيل 🚀";
                    } else {
                        $aiResponse = "✅ Your order has been placed successfully!" .
                            (!empty($customerName) ? " Thank you, {$customerName}" : '') . "\n" .
                            (!empty($productName)  ? "Product: {$productName}\n" : '') .
                            "Order #: {$realOrderNumber}\n" .
                            "We'll contact you shortly to confirm the details ðŸš€";
                    }

                    // Persist completed checkout state so tests/callers can verify the order ID
                    // A completed order keeps only its idempotency/status
                    // facts. Removing customer details and product selections
                    // prevents a later confirmation from reusing stale size,
                    // address or contact data to create a duplicate order.
                    $completedState = [
                            'status'   => 'completed',
                            'order_id' => $realOrderId,
                            'order_number' => $realOrderNumber,
                            'confirmation_state' => 'order_placed',
                            'order_fingerprint' => $this->orderFingerprint($orderInput),
                            'order_product_id' => (string) ($orderInput['commerce_external_id'] ?? $orderInput['salla_product_id'] ?? ''),
                            'order_connection_id' => (string) $commerceChannel->id,
                        ];
                    $conversation->update([
                        'checkout_state' => $completedState,
                        'requires_human' => false,
                        'status' => 'open',
                        'ai_enabled' => true,
                        'escalated_at' => null,
                        'escalation_reason' => null,
                    ]);
                    HandoffTask::where('conversation_id', $conversation->id)
                        ->where('status', 'open')
                        ->update(['status' => 'resolved', 'resolved_at' => now()]);
                    $checkoutState = []; // prevent the persistence block below from re-saving it

                    // Fire order_status_changed event for workflow automation
                    try {
                        $engine = app(\App\Services\AutomationEngine::class);
                        $engine->executeWorkflowsForEvent(
                            \App\Services\AutomationEngine::EVENT_ORDER_STATUS_CHANGED,
                            $conversation,
                            ['order_id' => $realOrderId, 'status' => 'completed', 'event_id' => 'order-status:' . $realOrderId . ':completed']
                        );
                    } catch (\Throwable $e) {
                        Log::error('ProcessAutoReply: failed to fire order_status_changed event', [
                            'conversation_id' => $conversation->id,
                            'exception_type' => get_class($e),
                        ]);
                    }

                    Log::info('ProcessAutoReply: commerce order created and reply overridden with real order ID', [
                        'conversation_id' => $conversation->id,
                        'order_id'        => $realOrderId,
                    ]);
                } elseif (($orderData['status'] ?? null) === 'needs_fields') {
                    // A 422 is a definitive field-validation response, not a
                    // failed/ambiguous order attempt. Keep checkout active and
                    // ask only for the fields Salla explicitly rejected.
                    $freshState = $conversation->fresh()->checkout_state;
                    $updatedCheckoutState = is_array($freshState) ? $freshState : $updatedCheckoutState;
                    $updatedCheckoutState['confirmation_state'] = 'collecting_info';
                    unset(
                        $updatedCheckoutState['confirmation_summary_message_id'],
                        $updatedCheckoutState['confirmation_summary_fingerprint'],
                        $updatedCheckoutState['confirmation_summary_sent_at']
                    );
                    // Salla's 422 is the ground truth: whatever field it named must be
                    // treated as missing even if our local checkout_state holds a stale
                    // or placeholder value (e.g. "__not_applicable__") for it. Otherwise
                    // computeFieldStatus() won't flag it, the intersection below collapses
                    // to empty, and the customer gets stuck in an infinite confirm loop.
                    foreach ((array) ($orderData['missing_fields'] ?? []) as $sallaMissingField) {
                        unset($updatedCheckoutState[$sallaMissingField]);
                    }
                    // Product options rejected by Salla: the next customer reply must be read
                    // as an option choice, not as free-text order detail.
                    if (in_array('salla_options', (array) ($orderData['missing_fields'] ?? []), true)) {
                        $updatedCheckoutState['confirmation_state'] = 'selecting_salla_option';
                    }
                    $conversation->update(['checkout_state' => $updatedCheckoutState]);
                    $checkoutState = $updatedCheckoutState;
                    $fieldStatus = $checkoutService->computeFieldStatus(
                        $updatedCheckoutState,
                        $sallaCheckoutActive,
                        (array) ($updatedCheckoutState['salla_required_fields'] ?? [])
                    );
                    $labels = $detectedLanguage === 'arabic' ? [
                        'email' => 'البريد الإلكتروني',
                        'postal_code' => 'الرمز البريدي',
                        'building_number' => 'رقم المبنى',
                        'street_number' => 'رقم الشارع',
                        'address_line' => 'اسم الشارع وعنوان التوصيل',
                        'delivery_city' => 'مدينة التوصيل التي يدعمها المتجر',
                        'block' => 'رقم الدور أو الشقة',
                        'district' => 'اسم الحي',
                        'geo_coordinates' => 'المدينة والعنوان بالتفصيل لتحديد الموقع',
                        'short_address' => 'العنوان الوطني المختصر، إن وجد',
                        'additional_number' => 'الرقم الإضافي للمبنى',
                        'address' => 'عنوان التوصيل بالكامل',
                        'salla_options' => 'اختيار المنتج مثل المقاس أو اللون',
                    ] : [
                        'email' => 'email address',
                        'postal_code' => 'postal code',
                        'building_number' => 'building or house number',
                        'street_number' => 'street number',
                        'address_line' => 'street and delivery address line',
                        'delivery_city' => 'a delivery city supported by the store',
                        'block' => 'block or floor (for example, a block name or floor number)',
                        'district' => 'district/neighborhood',
                        'geo_coordinates' => 'clear delivery city/address details so I can look up its location',
                        'short_address' => 'Saudi National Address short code',
                        'additional_number' => 'additional address number',
                        'address' => 'complete delivery address',
                        'salla_options' => 'product option (for example size or color)',
                    ];
                    // Trust Salla's own 422 response for which fields are still
                    // missing — it is the authority on what the store requires.
                    // (Previously this intersected against our local field-status
                    // check, which could silently collapse to an empty set and
                    // produce a vague, unfixable message, trapping the customer
                    // in an infinite "confirm order" loop.)
                    $stillMissing = array_values(array_diff(
                        array_unique((array) ($orderData['missing_fields'] ?? [])),
                        ['geo_coordinates']
                    ));
                    $requestedFields = array_values(array_unique(array_map(
                        fn ($field) => $labels[$field] ?? str_replace('_', ' ', (string) $field),
                        $stillMissing
                    )));
                    if ($requestedFields === []) {
                        $aiResponse = $detectedLanguage === 'arabic'
                            ? 'لم يتم إرسال الطلب بعد؛ تعذّر على نظام الشحن تحديد موقع العنوان تلقائيًا. وصلني اسم المدينة والعنوان، فلا تحتاج إلى إرسال إحداثيات. راجع المدينة والحي والشارع، وإذا كانت صحيحة أقدر أوصلك بموظف يكمل معك.'
                            : 'The order has not been submitted; the shipping system could not locate the address automatically. I received the city and address, so you do not need to send coordinates. Please check the city, district, and street; if they are correct, I can connect you with a team member to complete the order.';
                    } elseif ($detectedLanguage === 'arabic') {
                        $aiResponse = 'لم يتم إرسال الطلب بعد. طلب المتجر هذه البيانات الإضافية فقط: ' . implode('، ', $requestedFields) . '. أرسلها في رسالة واحدة من فضلك.';
                    } else {
                        $aiResponse = 'The order has not been submitted yet. The store requires these additional details: ' . implode(', ', $requestedFields) . '. Please send them in one message.';
                    }
                    Log::info('ProcessAutoReply: Salla validation requirements collected without escalating checkout', [
                        'conversation_id' => $conversation->id,
                        'missing_fields' => (array) ($orderData['missing_fields'] ?? []),
                    ]);
                } else {
                    // ❌ FAILURE — Salla API returned no order ID or threw an exception
                    $attemptFingerprint = $this->orderFingerprint($orderInput);
                    $latestAttempt = \App\Models\CommerceOrderAttempt::query()
                        ->where('conversation_id', $conversation->id)
                        ->where('connection_id', $commerceChannel?->id)
                        ->whereIn('status', ['submitting', 'unknown'])
                        ->latest('id')
                        ->first();
                    $providerOutcomeUnknown = in_array($latestAttempt?->status, ['submitting', 'unknown'], true);
                    Log::error('ProcessAutoReply: commerce order creation returned no order ID — sending honest failure reply', [
                        'conversation_id' => $conversation->id,
                        'provider' => $commerceChannel?->type,
                        'attempt_status' => $latestAttempt?->status,
                    ]);

                    $conversation->update([
                        'requires_human' => true,
                        'ai_enabled' => false,
                        'status' => 'pending_human',
                        'escalated_at' => now(),
                        'escalation_reason' => $commerceChannel->type . '_order_creation_failed',
                    ]);
                    app(\App\Services\HandoffTaskService::class)->openForConversation(
                        $conversation,
                        $commerceChannel->type . '_order_creation_failed',
                        'high'
                    );

                    if ($providerOutcomeUnknown && $detectedLanguage === 'arabic') {
                        $aiResponse = "تعذر علينا التأكد مما إذا كان المتجر قد قبل الطلب. حفظنا بياناتك ليتحقق منها الفريق، فضلاً لا تعِد إرسال الطلب حتى نؤكد لك النتيجة.";
                    } elseif ($providerOutcomeUnknown) {
                        $aiResponse = "I couldn't verify whether the store accepted the order. I've saved your checkout for the team to verify. Please don't submit it again until we confirm the result.";
                    } elseif ($detectedLanguage === 'arabic') {
                        $aiResponse = "عذراً، واجهنا مشكلة تقنية في إتمام طلبك الآن 😔\n" .
                            "سيتواصل معك أحد فريقنا قريباً لإتمام الطلب يدوياً. نعتذر عن الإزعاج.";
                    } else {
                        $aiResponse = "I'm sorry, we encountered a technical issue processing your order ðŸ˜”\n" .
                            "A team member will contact you shortly to complete your order manually. We apologize for the inconvenience.";
                    }

                    $failedAttempts = (int) ($updatedCheckoutState['failed_order_attempts'] ?? 0) + 1;
                    if ($providerOutcomeUnknown) {
                        $updatedCheckoutState['confirmation_state'] = 'provider_outcome_unknown';
                        $updatedCheckoutState['provider_attempt_status'] = $latestAttempt?->status;
                        $conversation->update(['checkout_state' => $updatedCheckoutState]);
                    } elseif ($failedAttempts >= 2) {
                        $conversation->update([
                            'checkout_state' => null,
                            'requires_human' => true,
                            'escalated_at' => now(),
                            'escalation_reason' => $commerceChannel->type . '_order_creation_failed',
                        ]);
                        $updatedCheckoutState = [];
                        $checkoutState = [];
                        Log::warning('ProcessAutoReply: cleared checkout state after repeated order creation failures', [
                            'conversation_id' => $conversation->id,
                            'failed_attempts' => $failedAttempts,
                        ]);
                    } else {
                        $updatedCheckoutState['failed_order_attempts'] = $failedAttempts;
                        $updatedCheckoutState['confirmation_state'] = 'confirmation_pending';
                        $conversation->update(['checkout_state' => $updatedCheckoutState]);
                    }
                }
        }
        // ── END REAL SALLA ORDER CREATION ────────────────────────────────────

        // ── CHECKOUT STATE PERSISTENCE ─────────────────────────────────────
        // Never let an AI success claim escape when a customer confirms before
        // Salla's required receiver/shipping details have been collected.
        if (
            $sallaChannel
            && !$isOrderStatus
            && !empty($updatedCheckoutState['salla_product_id'])
            && empty($fieldStatus['is_complete'])
            && !$isProductBrowseMessage
            && ($containsConfirmPhrase
                || $intent === 'place_order'
                || \App\Services\ShopifyOrderIntent::wantsToOrder((string) $message->content))
        ) {
            $missingLabels = $detectedLanguage === 'arabic' ? [
                'full_name' => 'الاسم بالكامل',
                'phone' => 'رقم الهاتف أو الجوال',
                'address' => 'عنوان التوصيل بالتفصيل',
                'email' => 'البريد الإلكتروني',
                'postal_code' => 'الرمز البريدي',
                'geo_coordinates' => 'اسم المدينة والحي والشارع بوضوح لتحديد موقع التوصيل',
                'building_number' => 'رقم المبنى',
                'street_number' => 'رقم الشارع',
                'address_line' => 'اسم الشارع وعنوان التوصيل',
                'delivery_city' => 'مدينة التوصيل، مثل الرياض أو جدة',
                'block' => 'رقم الدور أو الشقة',
                'district' => 'اسم الحي',
                'short_address' => 'العنوان الوطني المختصر، إن وجد',
                'additional_number' => 'الرقم الإضافي للمبنى، إن وجد',
            ] : [
                'full_name' => 'full name',
                'phone' => 'phone number',
                'address' => 'delivery address',
                'email' => 'email address',
                'postal_code' => 'postal code',
                'geo_coordinates' => 'a clear delivery city so its location can be looked up automatically',
                'building_number' => 'building number',
                'street_number' => 'street number',
                'address_line' => 'street and delivery address line',
                'delivery_city' => 'delivery city supported by the store',
                'block' => 'block or floor (for example, a block name or floor number)',
                'district' => 'district/neighborhood',
                'short_address' => 'short / national address code',
                'additional_number' => 'additional address number',
            ];
            $missingDetails = array_map(
                fn ($field) => $missingLabels[$field] ?? str_replace('_', ' ', $field),
                $fieldStatus['missing_fields']
            );

            // A customer whose own address genuinely has no Saudi National
            // Address component (block/district/short address/etc.) cannot
            // ever answer the same question, and the AI would otherwise ask
            // for it forever. Track how many times in a row the exact same
            // set of fields has stayed unresolved across a customer reply; on
            // the second repeat, hand off to a human instead of looping.
            $missingFieldsKey = implode(',', $fieldStatus['missing_fields']);
            $previousAskKey = $updatedCheckoutState['missing_fields_last_asked'] ?? null;
            $repeatCount = ($previousAskKey === $missingFieldsKey)
                ? (int) ($updatedCheckoutState['missing_fields_repeat_count'] ?? 0) + 1
                : 1;
            $updatedCheckoutState['missing_fields_last_asked'] = $missingFieldsKey;
            $updatedCheckoutState['missing_fields_repeat_count'] = $repeatCount;

            if ($repeatCount >= 3) {
                if ($detectedLanguage === 'arabic') {
                    $aiResponse = "وصلتني بياناتك، شكرًا. ما زالت بيانات العنوان التالية غير متوفرة: " . implode('، ', $missingDetails) . ". لن أكرر طلبها؛ سأحوّلك لموظف ليتأكد من إمكانية إتمام الطلب بدونها.";
                } else {
                    $aiResponse = "Thanks, I received your details. These address details are still missing: " . implode(', ', $missingDetails) . ". I won't keep asking; I'm connecting you with a team member to check whether the order can proceed without them.";
                }
                app(\App\Services\HandoffTaskService::class)->openForConversation(
                    $conversation,
                    'checkout_stuck_missing_field',
                    'normal'
                );
                $conversation->update([
                    'requires_human' => true,
                    'ai_enabled' => false,
                    'status' => 'pending_human',
                    'escalated_at' => now(),
                    'escalation_reason' => 'checkout_stuck_missing_field',
                ]);
                Log::info('ProcessAutoReply: escalated to human after repeated unresolved checkout fields', [
                    'conversation_id' => $conversation->id,
                    'missing_fields' => $fieldStatus['missing_fields'],
                    'repeat_count' => $repeatCount,
                ]);
            } elseif ($repeatCount === 2) {
                if ($detectedLanguage === 'arabic') {
                    $aiResponse = 'وصلتني التفاصيل اللي أرسلتها. لسه مش قادر أقرأ: ' . implode('، ', $missingDetails) . '. لو عندك العنوان الوطني المختصر اكتبه مثل ABCD1234، والرقم الإضافي اكتبه كما هو في العنوان. لو معلومة منهم مش موجودة عندك، اكتب «مش متوفر» عشان أوضح لك الخطوة التالية.';
                } else {
                    $aiResponse = 'I received the details you sent, but I still could not identify: ' . implode(', ', $missingDetails) . '. Please use the exact labels shown; for example, a short national address like ABCD1234. If a detail is not available to you, say “not available” and I will explain the next step.';
                }
            } else {
                if ($detectedLanguage === 'arabic') {
                    $aiResponse = "تمام، أقدر أساعدك في الطلب. قبل ما أجهزه للتأكيد، ابعتلي البيانات دي كلها في رسالة واحدة: " . implode('، ', $missingDetails) . ".";
                } else {
                    $aiResponse = "Sure, I can help with that. Before I prepare the order for your confirmation, please send these details together in one message: " . implode(', ', $missingDetails) . ".";
                }
            }

            $conversation->update(['checkout_state' => $updatedCheckoutState]);
            Log::info('ProcessAutoReply: blocked Salla order confirmation while required details are missing', [
                'conversation_id' => $conversation->id,
                'missing_fields' => $fieldStatus['missing_fields'],
                'repeat_count' => $repeatCount,
            ]);
        }

        // The model may claim completion or ask again for a field the customer
        // already supplied. Verify both against durable checkout/order state
        // immediately before the reply can be sent.
        $guardCheckoutState = array_merge(
            is_array($conversation->fresh()->checkout_state) ? $conversation->fresh()->checkout_state : [],
            is_array($updatedCheckoutState) ? $updatedCheckoutState : []
        );
        // The model may not ask for its own YES while a summary is pending, and a placeholder
        // phone in the customer's message is never accepted silently.
        $aiResponse = \App\Services\ConfirmationGuard::replaceModelConfirmation(
            (string) $aiResponse,
            $checkoutState['confirmation_state'] ?? null,
            (bool) $containsConfirmPhrase,
            !empty($checkoutState['confirmation_summary_sent_at']),
            (string) $detectedLanguage
        );
        // Block claimed-action hallucinations across ALL conversation states.
        if (\App\Models\FeatureFlag::isEnabled('ai_action_claim_guard', (int) $conversation->business_id)
            && ($orderCreationFailedReason ?? null) !== 'order_confirmation_disabled') {
            $successfulActionTypes = \App\Models\AiActionLog::query()
                ->where('conversation_id', $conversation->id)
                ->where('message_id', $message->id)
                ->where('status', 'executed')
                ->pluck('action_type')
                ->map(fn ($type) => (string) $type)
                ->all();
            $aiResponse = \App\Services\ConfirmationGuard::blockUngroundedActionClaim(
                (string) $aiResponse,
                !empty($realOrderId) || ($isOrderStatus && !empty($sallaContext)),
                $detectedLanguage,
                $successfulActionTypes
            );
        }
        if (trim((string) $message->content) === '') {
            $aiResponse = \App\Services\ConfirmationGuard::attachmentWithoutTextReply($detectedLanguage === 'arabic');
        }
        if (($placeholderReply = \App\Services\ConfirmationGuard::placeholderPhoneReply((string) $message->content)) !== null) {
            $aiResponse = $placeholderReply;
        }
        $greetingGuard = app(\App\Services\GreetingRepeatGuard::class)->apply($message, (string) $aiResponse);
        $aiResponse = $greetingGuard['reply'];

        $claimGuard = app(\App\Services\OrderClaimGuard::class)->inspect(
            $conversation,
            (string) $aiResponse,
            $guardCheckoutState,
            $fieldStatus['missing_fields'] ?? [],
            $detectedLanguage
        );
        if (
            !$claimGuard['allowed']
            && !empty($claimGuard['should_send_order_summary'])
            && !$isOrderStatus
            && !$containsConfirmPhrase
            && ($aiResult['intent'] ?? null) !== 'order_status'
            && $shouldPresentOrderSummary
            && !empty($fieldStatus['is_complete'])
            && !$shopifyVariantSelectionRequired
            && !empty($guardCheckoutState['product_name'])
            && !empty($guardCheckoutState['commerce_external_id'] ?? $guardCheckoutState['salla_product_id'] ?? null)
        ) {
            // Recover a complete checkout deterministically if the model asks
            // for a field that is already present. This message itself becomes
            // the required current summary; it still requires the customer to
            // confirm on a later turn before any provider API is called.
            $updatedCheckoutState = $guardCheckoutState;
            $updatedCheckoutState['confirmation_state'] = 'confirmation_pending';
            unset(
                $updatedCheckoutState['confirmation_summary_message_id'],
                $updatedCheckoutState['confirmation_summary_fingerprint'],
                $updatedCheckoutState['confirmation_summary_sent_at']
            );
            $conversation->update(['checkout_state' => $updatedCheckoutState]);
            $checkoutState = $updatedCheckoutState;
            $aiResponse = $this->buildOrderConfirmationSummary($updatedCheckoutState, $commerceChannel);
            $isOrderSummaryMessage = true;
            $knownFieldReason = $claimGuard['reason'];
            $claimGuard['decision'] = 'replaced';
            $claimGuard['reason'] = 'known_checkout_field_recovered_with_summary';
            $claimGuard['replacement'] = $aiResponse;
            Log::warning('ProcessAutoReply: replaced repeated known-field question with a fresh order summary', [
                'conversation_id' => $conversation->id,
                'message_id' => $message->id,
                'field_reason' => $knownFieldReason,
            ]);
        }
        if (!$claimGuard['allowed']
            && !empty($claimGuard['should_send_order_summary'])
            && !$shouldPresentOrderSummary
            && !$isOrderStatus) {
            // A known-field re-ask must not turn every unrelated held-checkout
            // turn into another order summary. Preserve the pending checkout
            // and give a short next-step reply instead.
            $aiResponse = $detectedLanguage === 'arabic'
                ? 'بيانات طلبك محفوظة. لو حابب تكمل، ابعت «تأكيد الطلب»؛ ولو عندك سؤال تاني اسألني.'
                : 'Your order details are saved. Reply “confirm order” when you want to continue, or ask me another question.';
            $claimGuard['allowed'] = true;
            $claimGuard['decision'] = 'replaced';
            $claimGuard['reason'] = 'known_field_reask_suppressed_during_held_checkout';
        }
        if (!$claimGuard['allowed']) {
            $aiResponse = $claimGuard['replacement'];
            $messageMetadata = is_array($replyMessage->metadata) ? $replyMessage->metadata : [];
            $messageMetadata['order_claim_guard'] = [
                'decision' => $claimGuard['decision'],
                'reason' => $claimGuard['reason'],
            ];
            $replyMessage->metadata = $messageMetadata;
            if ($claimGuard['requires_handoff']) {
                app(\App\Services\HandoffTaskService::class)->openForConversation(
                    $conversation,
                    'ai_claim_blocked',
                    'high'
                );
                $conversation->update([
                    'requires_human' => true,
                    'ai_enabled' => false,
                    'status' => 'pending_human',
                    'escalated_at' => now(),
                    'escalation_reason' => 'ai_claim_blocked',
                ]);
            }
            Log::info('Order claim guard', [
                'message_id' => $replyMessage->id,
                'conversation_id' => $conversation->id,
                'intent' => $intent,
                'decision' => $claimGuard['decision'],
                'reason' => $claimGuard['reason'],
                'missing_fields' => $fieldStatus['missing_fields'] ?? [],
                'order_record_present' => $claimGuard['order_record_present'],
            ]);
        } else {
            Log::info('Order claim guard', [
                'message_id' => $replyMessage->id,
                'conversation_id' => $conversation->id,
                'intent' => $intent,
                'decision' => 'allowed',
                'reason' => $claimGuard['reason'],
                'missing_fields' => $fieldStatus['missing_fields'] ?? [],
                'order_record_present' => $claimGuard['order_record_present'],
            ]);
        }

        if (\App\Models\FeatureFlag::isEnabled('ai_action_claim_guard', (int) $conversation->business_id)) {
            $actionClaimGuard = app(\App\Services\AIActionClaimGuard::class)->inspect(
                (string) $aiResponse,
                $detectedLanguage
            );
            if ($actionClaimGuard['blocked']) {
                $aiResponse = $actionClaimGuard['replacement'];
                $messageMetadata = is_array($replyMessage->metadata) ? $replyMessage->metadata : [];
                $messageMetadata['ai_action_claim_guard'] = [
                    'decision' => 'replaced',
                    'reason' => $actionClaimGuard['reason'],
                ];
                $replyMessage->metadata = $messageMetadata;
                Log::warning('AI action claim replaced', [
                    'business_id' => (int) $conversation->business_id,
                    'conversation_id' => $conversation->id,
                    'message_id' => $replyMessage->id,
                    'reason' => $actionClaimGuard['reason'],
                ]);
            }
        }

        // Give deterministic guidance for these two common cases after model
        // and claim guards have run, so generic fallbacks cannot obscure them.
        $confirmedSallaCheckoutWithAutomationOff = $sallaChannel
            && !$autoOrderConfirmationEnabled
            && $containsConfirmPhrase
            && empty($realOrderId)
            && ($checkoutState['confirmation_state'] ?? null) !== 'order_placed'
            && !empty($checkoutState['salla_product_id'])
            && !empty($fieldStatus['is_complete']);
        if ($confirmedSallaCheckoutWithAutomationOff) {
            $aiResponse = $detectedLanguage === 'arabic'
                ? 'لم يتم إرسال الطلب؛ لأن الإرسال التلقائي متوقف حاليًا. سيتواصل معك أحد أفراد الفريق لمساعدتك في إتمامه.'
                : 'Your order has not been submitted because automatic order submission is currently off. A team member will follow up to help complete it.';
            app(\App\Services\HandoffTaskService::class)->openForConversation(
                $conversation,
                'order_confirmation_disabled',
                'normal'
            );
            $conversation->update([
                'requires_human' => true,
                'status' => 'pending_human',
                'escalated_at' => now(),
                'escalation_reason' => 'order_confirmation_disabled',
            ]);
        }

        $confirmedSallaCheckoutWithoutShippingQuote = $sallaChannel
            && $autoOrderConfirmationEnabled
            && $containsConfirmPhrase
            && empty($checkoutState['salla_shipping_quote'])
            && !empty($checkoutState['salla_product_id'])
            && !empty($fieldStatus['is_complete']);
        if ($confirmedSallaCheckoutWithoutShippingQuote) {
            $aiResponse = $detectedLanguage === 'arabic'
                ? 'لم أتمكن من تأكيد رسوم التوصيل الآن، لذلك لم أرسل الطلب. سيؤكد لك فريق المتجر الإجمالي قبل إتمامه.'
                : 'I could not confirm the delivery fee, so I have not submitted the order. The store team will confirm the total before it is placed.';
            app(\App\Services\HandoffTaskService::class)->openForConversation(
                $conversation,
                'salla_shipping_quote_unavailable',
                'normal'
            );
        }

        if ($isOrderStatus && $sallaChannel) {
            $currentStatusText = trim((string) $message->content);
            $hasOrderNumber = (bool) preg_match(
                '/(?:\border\b|طلب)\s*(?:number|no\.?|رقم)?\s*[:#-]?\s*#?\d{4,12}|^\s*#?\d{4,12}\s*$/iu',
                $currentStatusText
            );
            $hasPhoneNumber = (bool) preg_match(
                '/(?:\b(?:phone|mobile|telephone)\b|رقم\s*(?:الجوال|الهاتف))\s*[:#-]?\s*\+?\d(?:[\s().-]*\d){7,14}|^\s*\+?\d[\d\s().-]{7,17}\s*$/iu',
                $currentStatusText
            );
            if (!empty($sallaContext)) {
                $orderSummary = $this->parseSallaContext($sallaContext);
                $number = trim((string) ($orderSummary['order_number'] ?? ''));
                $status = trim((string) ($orderSummary['status'] ?? ''));
                $total = trim((string) ($orderSummary['total'] ?? ''));
                $currency = trim((string) ($orderSummary['currency'] ?? 'SAR'));
                $shipping = trim((string) ($orderSummary['shipping_status'] ?? ''));
                $aiResponse = $detectedLanguage === 'arabic'
                    ? 'لقيت طلبك' . ($number !== '' ? " رقم #{$number}" : '') . ($status !== '' ? " وحالته: {$status}" : '')
                        . ($total !== '' ? "، والإجمالي {$total} {$currency}" : '')
                        . ($shipping !== '' && $shipping !== 'Not shipped' ? "، وحالة الشحن: {$shipping}" : '') . '.'
                    : 'I found your order' . ($number !== '' ? " #{$number}" : '') . ($status !== '' ? " with status: {$status}" : '')
                        . ($total !== '' ? ". Total: {$total} {$currency}" : '')
                        . ($shipping !== '' && $shipping !== 'Not shipped' ? ". Shipping: {$shipping}" : '') . '.';
            } elseif (!$hasOrderNumber && !$hasPhoneNumber && $this->senderPhoneDigits($conversation) === '') {
                $aiResponse = $detectedLanguage === 'arabic'
                    ? 'أكيد أساعدك في متابعة طلبك. ابعتلي رقم الطلب أو رقم الجوال المستخدم وقت الشراء عشان أقدر أتحقق من حالته.'
                    : 'I can help check your order. Please send the order number or the phone number used at checkout so I can look it up.';
            } else {
                $aiResponse = $detectedLanguage === 'arabic'
                    ? 'لم أتمكن من التحقق من الطلب بهذه البيانات حتى الآن. راجع رقم الجوال أو أرسل رقم الطلب، وسأحاول البحث عنه مرة أخرى.'
                    : 'I could not verify an order with those details yet. Please check the phone number or send the order number, and I will look it up again.';
            }
        }

        // The checkout state is already saved by the pre-AI confirmation block above.
        // No post-AI order creation — orders are created BEFORE the AI reply so
        // the AI can correctly reference the real order ID in its response.
        //
        // Order creation may replace an AI-generated success claim with the real
        // success or failure outcome. Persist that final text before sending it.
        if ($replyMessage->content !== $aiResponse) {
            $replyMessage->update(['content' => $aiResponse]);
        }

        // Only clear checkout state on a fresh greeting when there is no in-progress order.
        if ($updatedCheckoutState && $intent === 'greeting' && empty($updatedCheckoutState['order_id']) && empty($updatedCheckoutState['product_name'])) {
            $conversation->update(['checkout_state' => null]);
        }
        // ── END CHECKOUT STATE PERSISTENCE ─────────────────────────────────


        if ($channel->user_id) {
            broadcast(new \App\Events\MessageReceived($replyMessage, $message->conversation, $channel->user_id));
        }

        // Images (if any) were already sent above, before the text was composed,
        // so sendReply now only needs to deliver the (possibly overridden) text.
        $sendSuccess = $this->sendReply($channel, $message->conversation, $replyMessage);

        if ($sendSuccess && $isOrderSummaryMessage) {
            $latestCheckoutState = is_array($conversation->fresh()->checkout_state)
                ? $conversation->fresh()->checkout_state
                : [];
            $latestCheckoutState['confirmation_summary_message_id'] = $replyMessage->id;
            $latestCheckoutState['confirmation_summary_fingerprint'] = $this->orderFingerprint($latestCheckoutState);
            $latestCheckoutState['confirmation_summary_sent_at'] = now()->toIso8601String();
            $conversation->update(['checkout_state' => $latestCheckoutState]);
            Log::info('ProcessAutoReply: sent order summary recorded for confirmation', [
                'conversation_id' => $conversation->id,
                'incoming_message_id' => $message->id,
                'summary_message_id' => $replyMessage->id,
                'summary_send_status' => $replyMessage->fresh()->send_status,
                'summary_fingerprint_present' => !empty($latestCheckoutState['confirmation_summary_fingerprint']),
                'confirmation_state' => $latestCheckoutState['confirmation_state'] ?? null,
            ]);
        }
        
        // If send failed, provide fallback response to user
        if (!$sendSuccess) {
            Log::warning('ProcessAutoReply: Initial send failed, using fallback', [
                'conversation_id' => $conversation->id,
                'message_id' => $replyMessage->id
            ]);
            
            $fallbackMessage = "I apologize, but I'm having trouble sending my response right now. A human agent will follow up with you shortly.";
            
            $fallbackReply = Message::create([
                'conversation_id' => $message->conversation_id,
                'content' => $fallbackMessage,
                'direction' => 'outbound',
                'status' => 'auto',
                'is_ai' => false,
                'source' => 'send_failure_fallback',
                'send_status' => 'pending',
            ]);
            
            // Try one more time with simpler message
            $this->sendReply($channel, $message->conversation, $fallbackReply);
            
            // Notify about the send failure
            $this->notifyAIFailure($channel->user, $conversation, 'Message send failure');
        }
        // NOTE: Sequence enrollment for non-new-user messages (tag_added, no_reply, order_created)
        // is handled by their respective triggers (SallaWebhookJob, scheduled commands, etc.)
        // and NOT from ProcessAutoReply, to preserve separation of concerns.
        //
        // EXCEPTION: no_reply sequences ARE triggered from here because only ProcessAutoReply
        // knows the exact moment the AI replied. We schedule a delayed job anchored to this
        // specific AI reply message. The job checks at execution time if the customer replied.
        if ($sendSuccess) {
            try {
                $triggerSvc = app(SequenceTriggerService::class);
                $triggerSvc->scheduleNoReplyTimers($message->conversation, $replyMessage);
            } catch (\Exception $e) {
                // Never let sequence scheduling break the reply flow.
                Log::warning('ProcessAutoReply: failed to schedule no-reply timers', [
                    'conversation_id' => $conversation->id,
                    'exception_type' => get_class($e),
                ]);
            }

            // Evaluate workflows for this business
            try {
                $this->evaluateWorkflows($conversation, $message);
            } catch (\Exception $e) {
                // Never let workflow evaluation break the reply flow.
                Log::warning('ProcessAutoReply: failed to evaluate workflows', [
                    'conversation_id' => $conversation->id,
                    'exception_type' => get_class($e),
                ]);
            }
        }
    }

    /**
     * Determine the current pending action based on conversation state.
     */
    private function determinePendingAction(array $checkoutState, bool $isPlaceOrder, array $fieldStatus): string
    {
        if ($isPlaceOrder && !empty($checkoutState['salla_product_id'])) {
            if ($fieldStatus['is_complete']) {
                return 'confirm_order';
            }
            return 'collect_customer_info';
        }

        if ($isPlaceOrder && empty($checkoutState['salla_product_id'])) {
            return 'select_product';
        }

        return 'general_conversation';
    }

    // ── SEQUENCE INTEGRATION HELPERS ─────────────────────────────────────────

    /**
     * Determine whether the incoming message is the very first inbound message
     * on this conversation.
     *
     * "New user" means: this is the FIRST inbound message ever recorded on
     * the conversation. We count inbound messages rather than checking whether
     * the Conversation row is new, because the conversation is created by the
     * webhook handler before any job runs — so created_at is not a reliable
     * proxy for "has this person ever messaged us".
     *
     * We count messages with id <= $message->id to handle concurrent workers:
     * if two inbound messages are processed simultaneously, only the one whose
     * id is the lowest will be seen as the first message, which is correct.
     */
    private function isNewUserConversation(Conversation $conversation, Message $message): bool
    {
        // Count inbound messages on this conversation that were created at or
        // before the current message. If count == 1, this IS the first inbound
        // message, so this is a new user.
        $inboundCount = Message::where('conversation_id', $conversation->id)
            ->where('direction', 'inbound')
            ->where('id', '<=', $message->id)
            ->count();

        return $inboundCount === 1;
    }

    /**
     * Attempt to enroll the conversation in the first matching active
     * new_user-triggered sequence for this business.
     *
     * Returns true if a sequence was successfully triggered (meaning the AI
     * auto-reply must be skipped), false if no matching sequence exists or
     * if enrollment failed.
     *
     * Idempotency: a database cache lock keyed by conversation id prevents duplicate
     * enrollment attempts on job retries. SequenceEnrollmentService also
     * enforces uniqueness at the DB level (throws on duplicate active enrollment).
     */
    private function tryTriggerNewUserSequence(Conversation $conversation, int $businessId): bool
    {
        // Per-conversation idempotency lock: if this job is retried, we must not
        // enroll the conversation twice. The lock is held for 5 minutes — long
        // enough to cover the job retry window (backoff = 30s, tries = 3).
        $lockKey = "seq_new_user_lock:conv:{$conversation->id}";

        if (!Cache::store('database')->add($lockKey, 1, now()->addMinutes(5))) {
            // Another attempt already claimed or completed the enrollment.
            Log::info('ProcessAutoReply: new-user sequence lock already held — treating as already-triggered', [
                'conversation_id' => $conversation->id,
            ]);
            // Return true so this retry also skips the AI reply, consistent
            // with what the original attempt did.
            return true;
        }

        Log::info('ProcessAutoReply: checking new-user sequences', [
            'conversation_id' => $conversation->id,
            'business_id'     => $businessId,
        ]);

        // Find all active new-user sequences for this business, scoped to the
        // conversation's resolved bot (bot_id NULL = business-global, always
        // eligible; a bot-specific sequence only enrolls that bot's
        // conversations). We take the first matching one. If multiple match,
        // the one with the lowest id (oldest/first created) wins.
        $sequences = \App\Models\Sequence::where('business_id', $businessId)
            ->where('status', 'active')
            ->where('trigger_type', 'new_user')
            ->forBotScope($conversation->bot_id ? (int) $conversation->bot_id : null)
            ->orderBy('id')
            ->get();

        if ($sequences->isEmpty()) {
            Log::info('ProcessAutoReply: no active new-user sequences found', [
                'business_id' => $businessId,
            ]);
            return false;
        }

        Log::info('ProcessAutoReply: found active new-user sequences', [
            'business_id'   => $businessId,
            'sequence_ids'  => $sequences->pluck('id')->toArray(),
            'sequence_names' => $sequences->pluck('name')->toArray(),
        ]);

        // Resolve SequenceTriggerService from the container so Laravel injects
        // its constructor dependency (SequenceEnrollmentService) automatically.
        // This is the correct pattern — never call new SequenceTriggerService()
        // directly because that bypasses DI and causes the constructor error.
        /** @var SequenceTriggerService $triggerService */
        $triggerService = app(SequenceTriggerService::class);

        $enrolled = false;

        foreach ($sequences as $sequence) {
            try {
                $enrollment = $triggerService->enrollInSequence($sequence, $conversation, checkDuplicates: true);

                if ($enrollment !== null) {
                    Log::info('ProcessAutoReply: new-user sequence enrollment succeeded', [
                        'conversation_id' => $conversation->id,
                        'sequence_id'     => $sequence->id,
                        'sequence_name'   => $sequence->name,
                        'enrollment_id'   => $enrollment->id,
                    ]);
                    $enrolled = true;
                    // Only enroll in the first matching sequence. Stop here.
                    break;
                }

                // canEnroll returned false (e.g. already enrolled) — log and
                // continue to next sequence rather than failing silently.
                Log::info('ProcessAutoReply: new-user sequence enrollment skipped (not eligible)', [
                    'conversation_id' => $conversation->id,
                    'sequence_id'     => $sequence->id,
                ]);

            } catch (\Exception $e) {
                Log::error('ProcessAutoReply: new-user sequence enrollment threw an exception', [
                    'conversation_id' => $conversation->id,
                    'sequence_id'     => $sequence->id,
                    'exception_type' => get_class($e),
                ]);
                // Do NOT silently fall back to AI auto-reply on exception.
                // The sequence was likely partially set up; better to skip the
                // AI reply than to double-respond. Release the lock so a later
                // retry can try again.
                Cache::store('database')->forget($lockKey);
                // Propagate: let the job retry mechanism handle it.
                throw $e;
            }
        }

        if (!$enrolled) {
            // All sequences were ineligible (e.g. already enrolled from a race).
            // Release the lock so future new messages from this conversation
            // don't get stuck thinking a sequence is pending.
            Cache::store('database')->forget($lockKey);
        }

        return $enrolled;
    }

    /**
     * Evaluate active workflows for this conversation's business against the
     * message_received event.
     *
     * Delegates to AutomationEngine::executeWorkflowsForEvent(), which only
     * evaluates workflows whose trigger type is compatible with the event
     * (keyword, first_contact, message_received, time). The current inbound
     * message is passed through so trigger evaluation sees exactly the
     * message that fired the event — not arbitrary historical messages.
     *
     * This is intentionally fire-and-forget: errors are logged but do not
     * affect the main AI reply flow.
     */
    private function evaluateWorkflows(Conversation $conversation, Message $message): void
    {
        app(\App\Services\AutomationEngine::class)->executeWorkflowsForEvent(
            \App\Services\AutomationEngine::EVENT_MESSAGE_RECEIVED,
            $conversation,
            ['message' => $message],
            testMode: false
        );
    }

    // ── END SEQUENCE INTEGRATION HELPERS ─────────────────────────────────────

    /**
     * Normalize an AI confidence value to the app's canonical 0–1 scale.
     *
     * The AI JSON contract uses 0–1, but some providers/models occasionally
     * return 0–100 or a string. Normalizing here (and defensively again when
     * aggregating) prevents a stray out-of-range value from corrupting the
     * training-dashboard average. NULL stays NULL.
     */
    private static function normalizeConfidenceScore($value): ?float
    {
        if ($value === null) {
            return null;
        }
        if (!is_numeric($value)) {
            return null;
        }

        $v = (float) $value;
        if (!is_finite($v)) {
            return null;
        }

        // Provider returned 0–100 (e.g. 85.5).
        if ($v > 1 && $v <= 100) {
            $v = $v / 100;
        }

        return max(0.0, min(1.0, $v));
    }

    /**
     * Parse the SallaService::formatOrderForAI() string into a structured array.
     * Uses trimmed prefix matching so spacing differences don't silently drop fields.
     */
    private function parseSallaContext(string $formatted): array
    {
        $data = [];
        foreach (explode("\n", $formatted) as $rawLine) {
            $line = trim($rawLine);
            if (str_starts_with($line, 'Order #')) {
                $data['order_number'] = trim(str_replace('Order #', '', $line));
            } elseif (str_starts_with($line, 'Status:')) {
                $data['status'] = trim(str_replace('Status:', '', $line));
            } elseif (str_starts_with($line, 'Total:')) {
                $parts = explode(' ', trim(str_replace('Total:', '', $line)), 2);
                $data['total']    = $parts[0] ?? '';
                $data['currency'] = $parts[1] ?? 'SAR';
            } elseif (str_starts_with($line, 'Products:')) {
                $data['items'] = trim(str_replace('Products:', '', $line));
            } elseif (str_starts_with($line, 'Shipping Status:')) {
                $data['shipping_status'] = trim(str_replace('Shipping Status:', '', $line));
            } elseif (str_starts_with($line, 'Expected Delivery:')) {
                $data['delivery_date'] = trim(str_replace('Expected Delivery:', '', $line));
            }
        }

        // Filter out empty values so !empty($context['order_data']) works correctly
        $data = array_filter($data, fn($v) => $v !== '' && $v !== null);

        return $data;
    }

    
    /**
     * Sends product images directly to the customer via the channel's native
     * media API, BEFORE the text reply is composed. Returns how many of the
     * given product items were actually confirmed sent. Each image is
     * attempted independently (try/catch per item) so one bad URL doesn't
     * block the rest.
     *
     * This return value is what gates whether the AI's "here are the photos"
     * language is allowed to reach the customer -- see handle().
     *
     * CRITICAL ISSUE — REPLY-TO-PRODUCT CONTEXT: every successfully-sent
     * image is persisted as a ProductMessageMap row (outgoing message id →
     * Salla product), so that when the customer later replies directly to
     * one of these images, the exact product can be resolved deterministically
     * instead of the AI guessing from text/position/name similarity.
     *
     * @param array $productItems Each item: ['id','name','price','currency','quantity','image_url','sku'?]
     */
    private function sendImagesToCustomer(Channel $channel, Conversation $conversation, array $productItems, ?Channel $commerceChannel = null): int
    {
        $sentCount = 0;
        $recipientId = $conversation->sender_id;

        foreach ($productItems as $item) {
            $imageUrl = $item['image_url'] ?? null;
            if (empty($imageUrl)) {
                continue;
            }

            // Product images are separate billable outbound messages. Keep
            // one unit available for the explanatory text reply so a plan
            // limit cannot allow media while blocking the follow-up message.
            $business = $conversation->business;
            if ($business) {
                $usage = app(\App\Services\UsageMeter::class)->canUse(
                    $business,
                    \App\Services\UsageMeter::OUTBOUND_MESSAGES,
                );
                if (!$usage['allowed'] || (!$usage['unlimited'] && $usage['used'] >= max(0, $usage['limit'] - 1))) {
                    Log::info('ProcessAutoReply: product image skipped by outbound plan limit', [
                        'business_id' => $business->id,
                        'conversation_id' => $conversation->id,
                        'used' => $usage['used'],
                        'limit' => $usage['limit'],
                    ]);
                    break;
                }
            }

            $sentMessageId = null;
            $imageWasSent = false;

            try {
                if ($channel->type === 'whatsapp') {
                    if (($channel->metadata['whatsapp_provider'] ?? null) === 'cloud') {
                        $lastInbound = $conversation->messages()->where('direction', 'inbound')->latest('created_at')->first();
                        if (!$lastInbound || !$lastInbound->created_at->gt(now()->subHours(24))) {
                            continue;
                        }
                        $result = app(\App\Services\WhatsAppCloudApiService::class)
                            ->sendImage($channel, $recipientId, $imageUrl);
                        if (!empty($result['success'])) {
                            $sentMessageId = $result['message_id'] ?? null;
                            $sentCount++;
                            $imageWasSent = true;
                        }
                    } elseif (($channel->metadata['unofficial_risk_accepted'] ?? false) === true) {
                        // Evolution throws on non-2xx; a returned response means it accepted the send.
                        $response = (new EvolutionApiService())->sendMediaMessage($channel->page_id, $recipientId, $imageUrl, '', 'image');
                        $sentMessageId = $response['key']['id'] ?? null;
                        $sentCount++;
                        $imageWasSent = true;
                    }
                } elseif ($channel->type === 'telegram') {
                    $telegramResponse = app(\App\Services\TelegramService::class)
                        ->sendPhoto((string) $channel->access_token, $recipientId, $imageUrl, (string) ($item['name'] ?? ''));
                    if (!empty($telegramResponse['success'])) {
                        $providerMessageId = $telegramResponse['message_id'] ?? null;
                        $sentMessageId = $providerMessageId !== null ? $recipientId . ':' . $providerMessageId : null;
                        $sentCount++;
                        $imageWasSent = true;
                    }
                } elseif (in_array($channel->type, ['facebook', 'instagram'], true)) {
                    $accessToken = $channel->access_token;
                    $response = Http::timeout(10)->post(
                        'https://graph.facebook.com/' . config('services.meta.graph_version', 'v26.0') . "/me/messages?access_token={$accessToken}",
                        [
                            'recipient' => ['id' => $recipientId],
                            'message' => [
                                'attachment' => [
                                    'type' => 'image',
                                    'payload' => ['url' => $imageUrl, 'is_reusable' => true],
                                ],
                            ],
                        ]
                    );
                    if ($response->successful()) {
                        $sentCount++;
                        $sentMessageId = $response->json()['message_id'] ?? null;
                        $imageWasSent = true;
                    } else {
                        Log::error('ProcessAutoReply: image send failed (Facebook/Instagram)', [
                            'channel_type' => $channel->type,
                            'status'       => $response->status(),
                        ]);
                    }
                } else {
                    Log::info('ProcessAutoReply: image sending not supported for this channel type', [
                        'channel_type' => $channel->type,
                    ]);
                }
            } catch (\Exception $e) {
                Log::error('ProcessAutoReply: image send exception', [
                    'channel_type' => $channel->type,
                    'exception_type' => get_class($e),
                ]);
            }

            $productImageMessageId = null;
            if ($imageWasSent) {
                try {
                    $productImageMessage = Message::create([
                        'conversation_id' => $conversation->id,
                        'content' => trim((string) ($item['name'] ?? 'Product image')),
                        'type' => 'image',
                        'direction' => 'outbound',
                        'status' => 'sent',
                        'is_ai' => false,
                        'source' => 'ai_product_image',
                        'send_status' => 'sent',
                        'delivery_status' => 'sent',
                        'media_url' => $imageUrl,
                        'media_type' => 'image',
                        'metadata' => [
                            'platform_message_id' => $sentMessageId,
                            'commerce_product_id' => $item['commerce_external_id'] ?? $item['id'] ?? null,
                            'commerce_channel_id' => $commerceChannel?->id,
                            'commerce_provider' => $commerceChannel?->type,
                        ],
                    ]);
                    $productImageMessageId = $productImageMessage->id;
                } catch (\Throwable $e) {
                    Log::error('ProcessAutoReply: sent product image could not be recorded for billing', [
                        'conversation_id' => $conversation->id,
                        'platform_message_id' => $sentMessageId,
                        'exception_type' => get_class($e),
                    ]);
                }

                if ($productImageMessageId !== null && $channel->user_id) {
                    try {
                        $productImageMessage = Message::find($productImageMessageId);
                        if (!$productImageMessage) {
                            continue;
                        }
                        broadcast(new \App\Events\MessageReceived(
                            $productImageMessage,
                            $conversation,
                            (int) $channel->user_id,
                        ));
                    } catch (\Throwable $e) {
                        Log::warning('ProcessAutoReply: sent product image could not be broadcast to inbox', [
                            'conversation_id' => $conversation->id,
                            'message_id' => $productImageMessageId,
                            'exception_type' => get_class($e),
                        ]);
                    }
                }
            }

            // Product references are usable only when both the resolved store
            // and provider product identity are persisted. Telegram IDs include
            // chat ID so per-chat message IDs cannot collide.
            if ($imageWasSent) {
                $externalProductId = $item['commerce_external_id'] ?? $item['id'] ?? null;
                if ($commerceChannel && $externalProductId !== null && (string) $externalProductId !== '') {
                    try {
                        $internalProductId = \App\Models\Product::query()
                            ->where('business_id', $conversation->business_id)
                            ->where('commerce_channel_id', $commerceChannel->id)
                            ->where('commerce_external_id', (string) $externalProductId)
                            ->value('id');
                        $mapValues = [
                            'channel_id' => $channel->id,
                            'provider' => $channel->type,
                            'commerce_provider' => $commerceChannel->type,
                            'recipient_id' => (string) $recipientId,
                            'sent_at' => now(),
                            'internal_message_id' => $productImageMessageId,
                            'salla_product_id' => $commerceChannel->type === 'salla' ? (string) $externalProductId : null,
                            'internal_product_id' => $internalProductId,
                            'commerce_channel_id' => $commerceChannel->id,
                            'commerce_external_id' => (string) $externalProductId,
                            'sku' => $item['sku'] ?? null,
                            'product_name' => $item['name'] ?? null,
                            'product_price' => isset($item['price']) ? (string) $item['price'] : null,
                            'currency' => $item['currency'] ?? null,
                            'image_url' => $imageUrl,
                        ];

                        if ($sentMessageId !== null) {
                            \App\Models\ProductMessageMap::updateOrCreate(
                                ['conversation_id' => $conversation->id, 'platform_message_id' => $sentMessageId],
                                $mapValues,
                            );
                        } else {
                            \App\Models\ProductMessageMap::create([
                                'conversation_id' => $conversation->id,
                                'platform_message_id' => null,
                                ...$mapValues,
                            ]);
                        }

                        Log::info('ProcessAutoReply: persisted product message map', [
                            'conversation_id' => $conversation->id,
                            'platform_message_id_present' => $sentMessageId !== null,
                            'commerce_channel_id' => $commerceChannel->id,
                            'provider' => $channel->type,
                            'commerce_provider' => $commerceChannel->type,
                            'commerce_external_id' => (string) $externalProductId,
                            'internal_product_id' => $internalProductId,
                        ]);
                    } catch (\Throwable $e) {
                        Log::error('ProcessAutoReply: failed to persist product message map', [
                            'conversation_id' => $conversation->id,
                            'exception_type' => get_class($e),
                        ]);
                    }
                } else {
                    Log::warning('ProcessAutoReply: product image sent without safe commerce identity; reference disabled', [
                        'conversation_id' => $conversation->id,
                        'store_id_present' => $commerceChannel !== null,
                        'external_product_id_present' => $externalProductId !== null && (string) $externalProductId !== '',
                    ]);
                }
            }

        }

        return $sentCount;
    }

    private function sendReply(Channel $channel, Conversation $conversation, Message $replyMessage, array $images = []): bool
    {
        $senderId = $conversation->sender_id;
        $content = $replyMessage->content;

        $business = $conversation->business;
        if ($business && app(\App\Services\AutomationRunControl::class)->isBusinessPaused($business)) {
            $replyMessage->forceFill([
                'status' => 'failed',
                'send_status' => 'blocked',
                'delivery_status' => 'blocked',
                'error_details' => 'automation_paused',
            ])->save();
            Log::info('ProcessAutoReply: prevented send because global automation pause became active', [
                'business_id' => $business->id,
                'conversation_id' => $conversation->id,
                'message_id' => $replyMessage->id,
            ]);
            return false;
        }
        if ($business && app(\App\Services\UsageMeter::class)->blockMessageIfOverLimit($business, $replyMessage)) {
            return false;
        }

        try {
            $success = false;

            if ($channel->type === 'facebook') {
                $success = $this->sendFacebookReply($channel, $senderId, $content, $images, $replyMessage);
            } elseif ($channel->type === 'instagram') {
                $success = $this->sendInstagramReply($channel, $senderId, $content, $images, $replyMessage);
            } elseif ($channel->type === 'gmail') {
                $success = $this->sendGmailReply($channel, $conversation, $content);
            } elseif ($channel->type === 'whatsapp') {
                $success = app(\App\Services\ChannelMessageService::class)
                    ->sendMessage($channel, $conversation, $replyMessage, $images);
            } elseif ($channel->type === 'telegram') {
                $success = $this->sendTelegramReply($channel, $senderId, $content);
            } elseif ($channel->type === 'web_chat') {
                broadcast(new \App\Events\WebChatMessageReceived($replyMessage));
                $success = true;
            } elseif ($channel->type === 'tiktok') {
                $success = $this->sendTikTokReply($channel, $senderId, $content);
            }

            if ($success) {
                $replyMessage->update(['status' => 'sent', 'send_status' => 'sent', 'delivery_status' => 'sent', 'error_details' => null]);
                Log::info('ProcessAutoReply: reply sent successfully', [
                    'platform' => $channel->type,
                    'message_id' => $replyMessage->id,
                    'ai_replies_count' => $this->aiRepliesCount,
                ]);
            } else {
                if ($replyMessage->fresh()?->send_status !== 'blocked') {
                    $replyMessage->update([
                        'status' => 'failed', 'send_status' => 'failed', 'delivery_status' => 'failed',
                        'error_details' => $replyMessage->fresh()->error_details ?: 'provider_rejected',
                    ]);
                }
                Log::error('ProcessAutoReply: reply send failed', [
                    'platform' => $channel->type,
                    'message_id' => $replyMessage->id,
                ]);
            }

            return $success;

        } catch (\Exception $e) {
            if ($replyMessage->fresh()?->send_status !== 'blocked') {
                $replyMessage->update([
                    'status' => 'failed',
                    'send_status' => 'failed',
                    'delivery_status' => 'failed',
                    'error_details' => $e->getMessage(),
                ]);
            }
            Log::error('ProcessAutoReply: send reply exception', [
                'exception_type' => get_class($e),
                'platform' => $channel->type,
            ]);
            return false;
        }
    }

    private function sendFacebookReply(Channel $channel, string $recipientId, string $message, array $images = [], ?Message $messageRecord = null): bool
    {
        // Phase 4: the Meta Send API wire format was extracted to
        // MetaMessengerService so sequence messages (and any future outbound
        // flow) reuse the EXACT same implementation as AI auto-replies
        // (images first, then text; same token handling; same timeouts).
        $result = app(\App\Services\MetaMessengerService::class)->sendTextWithResult($channel, $recipientId, $message, $images);
        if ($messageRecord) {
            $metadata = $messageRecord->metadata ?? [];
            $metadata['provider_message_ids'] = $result['message_ids'] ?? [];
            if (!empty($result['message_id'])) {
                $metadata['platform_message_id'] = $result['message_id'];
            }
            $messageRecord->update(['metadata' => $metadata, 'error_details' => $result['error'] ?? null]);
        }
        return (bool) $result['success'];
    }

    /**
     * Detect language of message (simple heuristic)
     */
    private function detectLanguage(string $text): string
    {
        // Arabic character detection
        if (preg_match('/[\x{0600}-\x{06FF}]/u', $text)) {
            return 'arabic';
        }
        
        // Default to English
        return 'english';
    }

    private function sendInstagramReply(Channel $channel, string $recipientId, string $message, array $images = [], ?Message $messageRecord = null): bool
    {
        // Instagram uses the same API as Facebook with the page access token
        return $this->sendFacebookReply($channel, $recipientId, $message, $images, $messageRecord);
    }

    private function sendGmailReply(Channel $channel, Conversation $conversation, string $body): bool
    {
        $gmailCtrl = new GmailController();
        $client = $gmailCtrl->getAuthenticatedClient($channel);

        if (!$client) {
            Log::error('ProcessAutoReply: could not get Gmail client', ['channel_id' => $channel->id]);
            return false;
        }

        try {
            $gmail = new Gmail($client);
            $to = $conversation->sender_email ?? 'unknown';
            $subject = $conversation->subject ?? 'Re: Your message';
            $threadId = $conversation->sender_id; // sender_id stores threadId for Gmail

            // Get the original message Gmail-header Message-ID for threading
            $originalMessage = Message::where('conversation_id', $conversation->id)
                ->where('direction', 'inbound')
                ->whereNotNull('gmail_message_id')
                ->orderBy('created_at', 'asc')
                ->first();

            $inReplyToId = $originalMessage?->gmail_message_id ?? '';

            $raw = "To: {$to}\r\n";
            $raw .= "Subject: Re: {$subject}\r\n";
            if ($inReplyToId) {
                $raw .= "In-Reply-To: {$inReplyToId}\r\n";
                $raw .= "References: {$inReplyToId}\r\n";
            }
            $raw .= "Content-Type: text/plain; charset=utf-8\r\n\r\n";
            $raw .= $body;

            $encoded = rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');

            $messageObj = new GmailMessage();
            $messageObj->setRaw($encoded);
            if ($threadId) {
                $messageObj->setThreadId($threadId);
            }

            $gmail->users_messages->send('me', $messageObj);
            return true;

        } catch (\Exception $e) {
            Log::error('ProcessAutoReply: Gmail send exception', ['exception_type' => get_class($e)]);
            return false;
        }
    }

    private function sendTelegramReply(Channel $channel, string $chatId, string $message): bool
    {
        try {
            $botToken = $channel->access_token;
            $telegramService = new \App\Services\TelegramService();
            
            $success = $telegramService->sendMessage($botToken, $chatId, $message);

            if (!$success) {
                Log::error('ProcessAutoReply: Telegram send failed', [
                    'chat_id' => $chatId,
                ]);
            }

            return $success;

        } catch (\Exception $e) {
            Log::error('ProcessAutoReply: Telegram send exception', ['exception_type' => get_class($e)]);
            return false;
        }
    }

    private function sendTikTokReply(Channel $channel, string $userId, string $message): bool
    {
        try {
            // TikTok API requires specific OAuth scopes for commenting
            // This is a placeholder implementation - actual TikTok commenting requires
            // additional API setup and permissions
            $accessToken = $channel->access_token;
            $openId = $channel->metadata['open_id'] ?? null;

            if (!$openId) {
                Log::error('ProcessAutoReply: TikTok reply failed - no open_id', ['channel_id' => $channel->id]);
                return false;
            }

            // TikTok API for sending comments is restricted and requires special permissions
            // For now, we'll log this as a limitation
            Log::warning('ProcessAutoReply: TikTok direct replies are not supported via public API', [
                'channel_id' => $channel->id,
                'user_id' => $userId,
            ]);

            return false;

        } catch (\Exception $e) {
            Log::error('ProcessAutoReply: TikTok send exception', ['exception_type' => get_class($e)]);
            return false;
        }
    }

    private function formatShopifyOrderForAI(array $order): string
    {
        $orderNumber = $order['order_number'] ?? $order['id'] ?? 'N/A';
        $status = $order['financial_status'] ?? 'Unknown';
        $total = $order['total_price'] ?? '0';
        $currency = $order['currency'] ?? 'USD';
        $processedAt = $order['processed_at'] ?? 'Not specified';

        $products = [];
        foreach ($order['line_items'] ?? [] as $item) {
            $products[] = ($item['title'] ?? 'Unknown') . ' x' . ($item['quantity'] ?? 1);
        }

        return "Order #{$orderNumber}\nStatus: {$status}\nTotal: {$total} {$currency}\n" .
               "Products: " . implode(', ', $products) . "\n" .
               "Processed At: {$processedAt}";
    }

    /**
     * Notify business owner about AI failure
     */
    private function notifyAIFailure($user, $conversation, $error): void
    {
        try {
            Log::info('Notifying business owner about AI failure', [
                'user_id' => $user->id,
                'conversation_id' => $conversation->id,
                'error' => $error
            ]);

            // Log as critical for monitoring — omit PII (email, phone)
            Log::critical('AI Failure Notification', [
                'user_id'         => $user->id,
                'conversation_id' => $conversation->id,
                'error'           => $error,
                'timestamp'       => now()->toISOString(),
            ]);

            // Send email notification to business owner
            if ($user->email) {
                \Illuminate\Support\Facades\Mail::raw(
                    "AI Assistant Failure Alert\n\n" .
                    "The AI assistant encountered an error and needs human intervention.\n\n" .
                    "Conversation ID: {$conversation->id}\n" .
                    "Error: {$error}\n" .
                    "Time: " . now()->toDateTimeString() . "\n\n" .
                    "Please log in to review this conversation.",
                    function ($message) use ($user) {
                        $message->to($user->email)
                            ->subject('⚠️ AI Assistant Failed - Action Required');
                    }
                );
            }

        } catch (\Exception $e) {
            Log::error('Failed to notify business owner about AI failure', [
                'exception_type' => get_class($e),
                'user_id' => $user->id
            ]);
        }
    }

    /**
     * A confirmed checkout is deterministic and must still submit if the AI
     * provider is unavailable or returns malformed JSON.
     */
    private function submitConfirmedCheckoutWithoutAi(
        Conversation $conversation,
        Channel $channel,
        Channel $commerceChannel,
        array $checkoutState,
        string $detectedLanguage
    ): bool {
        $orderInput = [
            'salla_product_id' => $checkoutState['salla_product_id'],
            'commerce_external_id' => $checkoutState['commerce_external_id'] ?? $checkoutState['salla_product_id'],
            'commerce_channel_id' => $commerceChannel->id,
            'product_name' => $checkoutState['product_name'] ?? null,
            'product_price' => $checkoutState['product_price'] ?? 0,
            'commerce_variant_id' => $checkoutState['commerce_variant_id'] ?? null,
            'commerce_variant_title' => $checkoutState['commerce_variant_title'] ?? null,
            'full_name' => $checkoutState['full_name'] ?? $checkoutState['customer_name'] ?? null,
            'phone' => $checkoutState['customer_phone'] ?? $checkoutState['phone'] ?? '',
            'address' => $checkoutState['address'] ?? $checkoutState['customer_address'] ?? '',
            'email' => $checkoutState['email'] ?? $checkoutState['customer_email'] ?? null,
            'delivery_city' => $checkoutState['delivery_city'] ?? null,
            'salla_shipping_quote' => $checkoutState['salla_shipping_quote'] ?? null,
            'postal_code' => $checkoutState['postal_code'] ?? null,
            'geo_coordinates' => $checkoutState['geo_coordinates'] ?? null,
            'building_number' => $checkoutState['building_number'] ?? null,
            'street_number' => $checkoutState['street_number'] ?? null,
            'address_line' => $checkoutState['address_line'] ?? null,
            'block' => $checkoutState['block'] ?? null,
            'district' => $checkoutState['district'] ?? null,
            'short_address' => $checkoutState['short_address'] ?? null,
            'additional_number' => $checkoutState['additional_number'] ?? null,
            'salla_options' => $checkoutState['salla_options'] ?? [],
            'salla_option_titles' => $checkoutState['salla_option_titles'] ?? [],
            'salla_line_items' => $checkoutState['salla_line_items'] ?? [],
        ];

        $orderData = $this->createRealExternalOrder($conversation, $orderInput, $channel);
        if (($orderData['status'] ?? null) === 'already_completed') {
            $existingReference = trim((string) ($orderData['existing_order_number'] ?? ''));
            $reply = $detectedLanguage === 'arabic'
                ? 'هذا الطلب مسجل لدينا بالفعل' . ($existingReference !== '' ? " بالرقم #{$existingReference}" : '') . '، لذلك لم أنشئ طلبًا آخر.'
                : 'This checkout is already recorded' . ($existingReference !== '' ? " as order #{$existingReference}" : '') . ', so I did not create another order.';
            $this->sendSallaDeterministicReply($channel, $conversation, $reply, $detectedLanguage, 'salla_order_already_recorded');
            return true;
        }
        if (($orderData['status'] ?? null) === 'needs_fields') {
            $missingFields = array_values(array_diff((array) ($orderData['missing_fields'] ?? []), ['geo_coordinates']));
            $freshState = is_array($conversation->fresh()->checkout_state) ? $conversation->fresh()->checkout_state : $checkoutState;
            $freshState['confirmation_state'] = 'collecting_info';
            unset(
                $freshState['confirmation_summary_message_id'],
                $freshState['confirmation_summary_fingerprint'],
                $freshState['confirmation_summary_sent_at']
            );
            $conversation->update(['checkout_state' => $freshState]);
            $conversation->update([
                'requires_human' => false,
                'status' => 'open',
                'ai_enabled' => true,
                'escalated_at' => null,
                'escalation_reason' => null,
            ]);
            $labels = $detectedLanguage === 'arabic' ? [
                'street_number' => 'رقم الشارع', 'building_number' => 'رقم المبنى',
                'postal_code' => 'الرمز البريدي', 'email' => 'البريد الإلكتروني',
                'address_line' => 'اسم الشارع وعنوان التوصيل', 'delivery_city' => 'مدينة التوصيل',
                'block' => 'رقم الدور أو الشقة', 'district' => 'الحي',
                'short_address' => 'العنوان الوطني المختصر', 'additional_number' => 'الرقم الإضافي للمبنى',
            ] : [
                'street_number' => 'street number', 'building_number' => 'building number',
                'postal_code' => 'postal code', 'email' => 'email address',
                'address_line' => 'street and delivery address', 'delivery_city' => 'delivery city',
                'block' => 'block or floor', 'district' => 'district',
                'short_address' => 'short national address', 'additional_number' => 'additional building number',
            ];
            $details = array_values(array_filter(array_map(fn ($field) => $labels[$field] ?? null, $missingFields)));
            if ($details === [] && in_array('geo_coordinates', (array) ($orderData['missing_fields'] ?? []), true)) {
                $details = [$detectedLanguage === 'arabic'
                    ? 'اسم المدينة والحي والشارع ورقم المبنى بوضوح لتحديد موقع التوصيل'
                    : 'the city, district, street, and building number clearly so the delivery location can be identified'];
            }
            $reply = $detectedLanguage === 'arabic'
                ? 'لم يتم إرسال الطلب بعد. المتجر يحتاج البيانات التالية لإتمامه: ' . implode('، ', $details) . '. أرسلها في رسالة واحدة، وبعدها سأعرض عليك الملخص للتأكيد.'
                : 'Your order has not been submitted yet. The store requires: ' . implode(', ', $details) . '. Send those details in one message and I will show you the summary to confirm.';
            $replyMessage = Message::create([
                'conversation_id' => $conversation->id,
                'content' => $reply,
                'direction' => 'outbound', 'status' => 'auto', 'is_ai' => false,
                'source' => 'commerce_order_fields_required', 'send_status' => 'pending',
                'intent' => 'place_order', 'detected_language' => $detectedLanguage,
            ]);
            if ($channel->user_id) broadcast(new \App\Events\MessageReceived($replyMessage, $conversation, $channel->user_id));
            $this->sendReply($channel, $conversation, $replyMessage);
            return true;
        }
        $orderId = (string) ($orderData['order_id'] ?? '');
        if ($orderId === '') {
            $reply = $detectedLanguage === 'arabic'
                ? 'لم يتم إرسال الطلب، ولم يتم تأكيده في المتجر. واجهت مشكلة أثناء الإرسال، وسيتابع معك أحد أفراد الفريق للتأكد من النتيجة ومساعدتك.'
                : 'Your order was not confirmed by the store, so I cannot say it was placed. A team member will follow up to check the result and help complete it.';
            $conversation->update([
                'requires_human' => true, 'status' => 'pending_human', 'ai_enabled' => false,
                'escalated_at' => now(), 'escalation_reason' => 'commerce_order_submission_unconfirmed',
            ]);
            app(\App\Services\HandoffTaskService::class)->openForConversation($conversation, 'commerce_order_submission_unconfirmed');
            $replyMessage = Message::create([
                'conversation_id' => $conversation->id,
                'content' => $reply,
                'direction' => 'outbound', 'status' => 'auto', 'is_ai' => false,
                'source' => 'commerce_order_submission_unconfirmed', 'send_status' => 'pending',
                'intent' => 'place_order', 'detected_language' => $detectedLanguage,
            ]);
            if ($channel->user_id) broadcast(new \App\Events\MessageReceived($replyMessage, $conversation, $channel->user_id));
            $this->sendReply($channel, $conversation, $replyMessage);
            return true;
        }

        $customerOrderNumber = trim((string) ($orderData['order_number'] ?? $orderId));
        $productName = trim((string) ($orderInput['product_name'] ?? ''));
        $customerName = trim((string) ($orderInput['full_name'] ?? ''));
        $reply = $detectedLanguage === 'arabic'
            ? "✅ تم تأكيد طلبك بنجاح!" . ($customerName !== '' ? " شكرًا {$customerName}" : '') . "\n"
                . ($productName !== '' ? "المنتج: {$productName}\n" : '') . "رقم الطلب: #{$customerOrderNumber}\nسيتم التواصل معك قريبًا لتأكيد التفاصيل 🚀"
            : "✅ Your order has been placed successfully!" . ($customerName !== '' ? " Thank you, {$customerName}" : '') . "\n"
                . ($productName !== '' ? "Product: {$productName}\n" : '') . "Order #: {$customerOrderNumber}\nWe'll contact you shortly to confirm the details ðŸš€";

        // Do not retain the held checkout's old product option selections once
        // the store has confirmed order creation.
        $completedState = [
                'status' => 'completed',
                'order_id' => $orderId,
                'order_number' => $customerOrderNumber,
                'confirmation_state' => 'order_placed',
                'order_fingerprint' => $this->orderFingerprint($orderInput),
                'order_product_id' => (string) ($orderInput['commerce_external_id'] ?? $orderInput['salla_product_id'] ?? ''),
                'order_connection_id' => (string) $commerceChannel->id,
            ];
        $conversation->update([
            'checkout_state' => $completedState,
            'requires_human' => false,
            'status' => 'open',
            'ai_enabled' => true,
            'escalated_at' => null,
            'escalation_reason' => null,
        ]);
        HandoffTask::where('conversation_id', $conversation->id)
            ->where('status', 'open')
            ->update(['status' => 'resolved', 'resolved_at' => now()]);

        $replyMessage = Message::create([
            'conversation_id' => $conversation->id,
            'content' => $reply,
            'direction' => 'outbound',
            'status' => 'auto',
            'is_ai' => false,
            'source' => 'commerce_order_confirmation',
            'send_status' => 'pending',
            'intent' => 'place_order',
            'detected_language' => $detectedLanguage,
        ]);

        if ($channel->user_id) {
            broadcast(new \App\Events\MessageReceived($replyMessage, $conversation, $channel->user_id));
        }
        $sent = $this->sendReply($channel, $conversation, $replyMessage);
        Log::info('ProcessAutoReply: deterministic order confirmation sent after AI failure', [
            'conversation_id' => $conversation->id,
            'message_id' => $replyMessage->id,
            'connection_id' => $commerceChannel->id,
            'provider' => $commerceChannel->type,
            'order_id' => $orderId,
            'reply_sent' => $sent,
        ]);

        try {
            app(\App\Services\AutomationEngine::class)->executeWorkflowsForEvent(
                \App\Services\AutomationEngine::EVENT_ORDER_STATUS_CHANGED,
                $conversation,
                ['order_id' => $orderId, 'status' => 'completed', 'event_id' => 'order-status:' . $orderId . ':completed']
            );
        } catch (\Throwable $e) {
            Log::error('ProcessAutoReply: failed to fire order_status_changed event after deterministic order submission', [
                'conversation_id' => $conversation->id,
                'exception_type' => get_class($e),
            ]);
        }

        return true;
    }

    /** Handle Salla checkout collection and summary without an AI provider call. */
    private function handleSallaCheckoutWithoutAi(
        Channel $channel,
        Conversation $conversation,
        Channel $commerceChannel,
        array $checkoutState,
        array $fieldStatus,
        bool $optionSelectionRequired,
        ?array $optionPrompt,
        string $detectedLanguage,
        bool $variantPriceUnavailable = false
    ): bool {
        if ($variantPriceUnavailable || !empty($checkoutState['salla_variant_price_unresolved'])) {
            $reply = $detectedLanguage === 'arabic'
                ? 'لم أتمكن من التأكد من سعر المقاس الذي اخترته من المتجر، لذلك لم أرسل الطلب ولم أعرض سعرًا تقديريًا غير مؤكد. سيتحقق فريق المتجر من السعر.'
                : 'I could not verify the store price for that option, so I have not submitted the order or shown an unverified price. The store team will confirm it.';
            $this->sendSallaDeterministicReply($channel, $conversation, $reply, $detectedLanguage, 'salla_variant_price_unavailable');
            return true;
        }

        if ($optionSelectionRequired && !empty($optionPrompt['values'])) {
            $values = (array) $optionPrompt['values'];
            $choices = [];
            foreach ($values as $index => $value) {
                $choices[] = ($index + 1) . '. ' . trim((string) ($value['name'] ?? ''));
            }
            $optionName = trim((string) ($optionPrompt['name'] ?? 'الخيار'));
            $reply = $detectedLanguage === 'arabic'
                ? 'اختر ' . $optionName . ' المناسب لك، واكتب رقم الاختيار أو اسمه:' . "\n" . implode("\n", $choices)
                : 'Which ' . $optionName . ' would you like? Reply with its number or exact name:' . "\n" . implode("\n", $choices);
            $checkoutState['confirmation_state'] = 'selecting_salla_option';
            unset(
                $checkoutState['confirmation_summary_message_id'],
                $checkoutState['confirmation_summary_fingerprint'],
                $checkoutState['confirmation_summary_sent_at']
            );
            $conversation->update(['checkout_state' => $checkoutState]);
            $this->sendSallaDeterministicReply($channel, $conversation, $reply, $detectedLanguage, 'salla_checkout_option_required');
            return true;
        }

        if (empty($fieldStatus['is_complete'])) {
            $labels = $detectedLanguage === 'arabic' ? [
                'full_name' => 'الاسم الكامل', 'phone' => 'رقم الجوال', 'address' => 'عنوان التوصيل',
                'delivery_city' => 'مدينة التوصيل', 'street_number' => 'رقم الشارع', 'building_number' => 'رقم المبنى',
                'block' => 'الدور أو الشقة', 'postal_code' => 'الرمز البريدي', 'email' => 'البريد الإلكتروني',
                'district' => 'الحي', 'short_address' => 'العنوان الوطني المختصر', 'additional_number' => 'الرقم الإضافي للمبنى',
            ] : [
                'full_name' => 'full name', 'phone' => 'phone number', 'address' => 'delivery address',
                'delivery_city' => 'delivery city', 'street_number' => 'street number', 'building_number' => 'building number',
                'block' => 'block or floor', 'postal_code' => 'postal code', 'email' => 'email address',
                'district' => 'district', 'short_address' => 'short national address', 'additional_number' => 'additional building number',
            ];
            $missing = array_values(array_filter(array_map(
                static fn ($field) => $labels[$field] ?? null,
                array_diff((array) ($fieldStatus['missing_fields'] ?? []), ['geo_coordinates'])
            )));
            if ($missing === []) {
                return false;
            }
            $checkoutState['confirmation_state'] = 'collecting_info';
            unset(
                $checkoutState['confirmation_summary_message_id'],
                $checkoutState['confirmation_summary_fingerprint'],
                $checkoutState['confirmation_summary_sent_at']
            );
            $conversation->update(['checkout_state' => $checkoutState]);
            $reply = $detectedLanguage === 'arabic'
                ? 'أقدر أجهز طلبك. أرسل البيانات التالية كلها في رسالة واحدة: ' . implode('، ', $missing) . '.'
                : 'I can prepare that order. Please send these details together in one message: ' . implode(', ', $missing) . '.';
            $this->sendSallaDeterministicReply($channel, $conversation, $reply, $detectedLanguage, 'salla_checkout_fields_required');
            return true;
        }

        $checkoutState['confirmation_state'] = 'confirmation_pending';
        unset(
            $checkoutState['confirmation_summary_message_id'],
            $checkoutState['confirmation_summary_fingerprint'],
            $checkoutState['confirmation_summary_sent_at']
        );
        $conversation->update(['checkout_state' => $checkoutState]);
        $summary = $this->buildOrderConfirmationSummary($checkoutState, $commerceChannel);
        $summaryMessage = Message::create([
            'conversation_id' => $conversation->id,
            'content' => $summary,
            'direction' => 'outbound', 'status' => 'auto', 'is_ai' => false,
            'source' => 'salla_order_summary', 'send_status' => 'pending',
            'metadata' => ['order_summary' => true],
            'intent' => 'place_order', 'detected_language' => $detectedLanguage,
        ]);
        if ($channel->user_id) broadcast(new \App\Events\MessageReceived($summaryMessage, $conversation, $channel->user_id));
        if ($this->sendReply($channel, $conversation, $summaryMessage)) {
            $checkoutState['confirmation_summary_message_id'] = $summaryMessage->id;
            $checkoutState['confirmation_summary_fingerprint'] = $this->orderFingerprint($checkoutState);
            $checkoutState['confirmation_summary_sent_at'] = now()->toIso8601String();
            $conversation->update(['checkout_state' => $checkoutState]);
        }
        Log::info('SALLA_DETERMINISTIC_CHECKOUT_SUMMARY', [
            'conversation_id' => $conversation->id,
            'message_id' => $summaryMessage->id,
            'provider_called' => false,
            'summary_sent' => $summaryMessage->fresh()?->send_status === 'sent',
        ]);
        return true;
    }

    /** Handle every Salla confirmation from saved checkout state without an AI provider call. */
    private function handleSallaConfirmationWithoutAi(
        Channel $channel,
        Conversation $conversation,
        ?Channel $commerceChannel,
        array $checkoutState,
        array $fieldStatus,
        bool $hasValidOrderSummary,
        bool $autoSubmissionEnabled,
        string $detectedLanguage
    ): bool {
        if (!empty($checkoutState['salla_variant_price_unresolved'])) {
            $reply = $detectedLanguage === 'arabic'
                ? 'لم أتمكن من التأكد من سعر المقاس من المتجر، لذلك لم أرسل الطلب. سيتحقق فريق المتجر من السعر قبل إتمامه.'
                : 'I could not verify the selected option price with the store, so I have not submitted the order. The store team will confirm the price before completing it.';
            $this->sendSallaDeterministicReply($channel, $conversation, $reply, $detectedLanguage, 'salla_variant_price_unavailable');
            return true;
        }

        if (!$autoSubmissionEnabled) {
            $reply = $detectedLanguage === 'arabic'
                ? 'لم يتم إرسال الطلب؛ لأن الإرسال التلقائي متوقف حاليًا. سيتواصل معك أحد أفراد الفريق لمساعدتك في إتمامه.'
                : 'Your order has not been submitted because automatic order submission is currently off. A team member will follow up to help complete it.';
            $conversation->update([
                'requires_human' => true,
                'status' => 'pending_human',
                'escalated_at' => now(),
                'escalation_reason' => 'order_confirmation_disabled',
            ]);
            app(\App\Services\HandoffTaskService::class)->openForConversation(
                $conversation,
                'order_confirmation_disabled',
                'normal'
            );
            $this->sendSallaDeterministicReply($channel, $conversation, $reply, $detectedLanguage, 'salla_order_confirmation_disabled');
            return true;
        }

        if (empty($fieldStatus['is_complete'])) {
            $labels = $detectedLanguage === 'arabic' ? [
                'full_name' => 'الاسم الكامل', 'phone' => 'رقم الجوال', 'address' => 'عنوان التوصيل',
                'delivery_city' => 'مدينة التوصيل', 'street_number' => 'رقم الشارع', 'building_number' => 'رقم المبنى',
                'block' => 'الدور أو الشقة', 'postal_code' => 'الرمز البريدي', 'email' => 'البريد الإلكتروني',
                'district' => 'الحي', 'short_address' => 'العنوان الوطني المختصر', 'additional_number' => 'الرقم الإضافي للمبنى',
            ] : [
                'full_name' => 'full name', 'phone' => 'phone number', 'address' => 'delivery address',
                'delivery_city' => 'delivery city', 'street_number' => 'street number', 'building_number' => 'building number',
                'block' => 'block or floor', 'postal_code' => 'postal code', 'email' => 'email address',
                'district' => 'district', 'short_address' => 'short national address', 'additional_number' => 'additional building number',
            ];
            $missing = array_values(array_filter(array_map(
                static fn ($field) => $labels[$field] ?? null,
                array_diff((array) ($fieldStatus['missing_fields'] ?? []), ['geo_coordinates'])
            )));
            $reply = $detectedLanguage === 'arabic'
                ? 'لم يتم إرسال الطلب بعد. أرسل البيانات الناقصة في رسالة واحدة: ' . implode('، ', $missing) . '.'
                : 'Your order has not been submitted yet. Please send the missing details together in one message: ' . implode(', ', $missing) . '.';
            $this->sendSallaDeterministicReply($channel, $conversation, $reply, $detectedLanguage, 'salla_checkout_fields_required');
            return true;
        }

        if (empty($checkoutState['salla_shipping_quote'])) {
            $reply = $detectedLanguage === 'arabic'
                ? 'لم أتمكن من تأكيد رسوم التوصيل الآن، لذلك لم أرسل الطلب. سيتواصل معك فريق المتجر لتأكيد الإجمالي.'
                : 'I could not confirm the delivery fee, so I have not submitted the order. The store team will confirm the total with you.';
            $conversation->update([
                'requires_human' => true, 'status' => 'pending_human',
                'escalated_at' => now(), 'escalation_reason' => 'salla_shipping_quote_unavailable',
            ]);
            app(\App\Services\HandoffTaskService::class)->openForConversation($conversation, 'salla_shipping_quote_unavailable');
            $this->sendSallaDeterministicReply($channel, $conversation, $reply, $detectedLanguage, 'salla_shipping_quote_unavailable');
            return true;
        }

        if (!$hasValidOrderSummary) {
            $checkoutState['confirmation_state'] = 'confirmation_pending';
            unset(
                $checkoutState['confirmation_summary_message_id'],
                $checkoutState['confirmation_summary_fingerprint'],
                $checkoutState['confirmation_summary_sent_at']
            );
            $conversation->update(['checkout_state' => $checkoutState]);
            $summary = $this->buildOrderConfirmationSummary($checkoutState, $commerceChannel);
            $replyMessage = Message::create([
                'conversation_id' => $conversation->id,
                'content' => $summary,
                'direction' => 'outbound', 'status' => 'auto', 'is_ai' => false,
                'source' => 'salla_order_summary', 'send_status' => 'pending',
                'metadata' => ['order_summary' => true],
                'intent' => 'place_order', 'detected_language' => $detectedLanguage,
            ]);
            if ($channel->user_id) broadcast(new \App\Events\MessageReceived($replyMessage, $conversation, $channel->user_id));
            $sent = $this->sendReply($channel, $conversation, $replyMessage);
            if ($sent) {
                $checkoutState['confirmation_summary_message_id'] = $replyMessage->id;
                $checkoutState['confirmation_summary_fingerprint'] = $this->orderFingerprint($checkoutState);
                $checkoutState['confirmation_summary_sent_at'] = now()->toIso8601String();
                $conversation->update(['checkout_state' => $checkoutState]);
            }
            return true;
        }

        if (!$commerceChannel || $commerceChannel->type !== 'salla') {
            $reply = $detectedLanguage === 'arabic'
                ? 'لم يتم إرسال الطلب؛ تعذر تحديد متجر سلة المتصل بهذه المحادثة. سيتحقق فريق المتجر من الربط.'
                : 'Your order has not been submitted because I could not resolve the connected Salla store. The store team will check the connection.';
            app(\App\Services\HandoffTaskService::class)->openForConversation($conversation, 'salla_connection_unresolved');
            $this->sendSallaDeterministicReply($channel, $conversation, $reply, $detectedLanguage, 'salla_connection_unresolved');
            return true;
        }

        try {
            if ($this->submitConfirmedCheckoutWithoutAi($conversation, $channel, $commerceChannel, $checkoutState, $detectedLanguage)) {
                return true;
            }
        } catch (\Throwable $exception) {
            Log::error('ProcessAutoReply: deterministic Salla order submission threw', [
                'conversation_id' => $conversation->id,
                'connection_id' => $commerceChannel->id,
                'exception_type' => get_class($exception),
            ]);
        }

        $reply = $detectedLanguage === 'arabic'
            ? 'لم يتم تأكيد إرسال الطلب من متجر سلة. لن أكرر الإرسال حتى لا يتكرر الطلب؛ سيتحقق فريق المتجر من النتيجة.'
            : 'Salla did not confirm the order, so I have not marked it as placed. I will not retry automatically to avoid creating a duplicate; the store team will check the result.';
        app(\App\Services\HandoffTaskService::class)->openForConversation($conversation, 'salla_order_submission_unconfirmed');
        $this->sendSallaDeterministicReply($channel, $conversation, $reply, $detectedLanguage, 'salla_order_submission_unconfirmed');
        return true;
    }

    /** Persist and send a deterministic Salla reply without calling any AI provider. */
    private function sendSallaDeterministicReply(
        Channel $channel,
        Conversation $conversation,
        string $reply,
        string $detectedLanguage,
        string $source
    ): bool {
        $replyMessage = Message::create([
            'conversation_id' => $conversation->id,
            'content' => $reply,
            'direction' => 'outbound', 'status' => 'auto', 'is_ai' => false,
            'source' => $source, 'send_status' => 'pending',
            'intent' => str_contains($source, 'status') ? 'order_status' : 'place_order',
            'detected_language' => $detectedLanguage,
        ]);
        if ($channel->user_id) broadcast(new \App\Events\MessageReceived($replyMessage, $conversation, $channel->user_id));
        $sent = $this->sendReply($channel, $conversation, $replyMessage);
        Log::info('Salla deterministic reply sent', [
            'conversation_id' => $conversation->id,
            'message_id' => $replyMessage->id,
            'source' => $source,
            'send_success' => $sent,
            'provider_called' => false,
        ]);
        return $sent;
    }

    /**
     * Create a real order via external Salla/Shopify API integration if connected,
     * capturing external order ID and enforcing strict idempotency.
     */
    private function createRealExternalOrder(Conversation $conversation, array $checkoutState, Channel $channel): ?array
    {
        Log::info('ProcessAutoReply: commerce order creation handler entered', [
            'conversation_id' => $conversation->id,
            'channel_id' => $channel->id,
            'requested_connection_id' => $checkoutState['commerce_channel_id'] ?? null,
            'product_id_present' => !empty($checkoutState['commerce_external_id'] ?? $checkoutState['salla_product_id'] ?? null),
            'variant_id_present' => !empty($checkoutState['commerce_variant_id']),
            'customer_name_present' => !empty($checkoutState['full_name'] ?? $checkoutState['customer_name'] ?? ''),
            'customer_phone_present' => !empty($checkoutState['phone'] ?? $checkoutState['customer_phone'] ?? ''),
            'delivery_address_present' => !empty($checkoutState['address'] ?? $checkoutState['customer_address'] ?? ''),
        ]);
        $lockKey = "create_order_lock_conv_{$conversation->id}";
        $lock = Cache::store('database')->lock($lockKey, 10);

        if (!$lock->get()) {
            Log::warning('ProcessAutoReply: order creation skipped because conversation lock is held', [
                'conversation_id' => $conversation->id,
                'lock_key' => $lockKey,
            ]);
            return null;
        }

        try {
            // Re-read latest conversation state inside lock for idempotency
            $conversation->refresh();
            $currentState = is_array($conversation->checkout_state) ? $conversation->checkout_state : [];

            if (!empty($currentState['order_id']) && ($currentState['status'] ?? '') === 'completed') {
                $requestedFingerprint = $this->orderFingerprint($checkoutState);
                $storedFingerprint = $currentState['order_fingerprint'] ?? null;
                $legacyMatches = (string) ($currentState['order_product_id'] ?? $currentState['commerce_external_id'] ?? $currentState['salla_product_id'] ?? '')
                        === (string) ($checkoutState['commerce_external_id'] ?? $checkoutState['salla_product_id'] ?? '')
                    && (string) ($currentState['order_connection_id'] ?? $currentState['commerce_channel_id'] ?? '')
                        === (string) ($checkoutState['commerce_channel_id'] ?? '');

                if (($storedFingerprint && hash_equals($storedFingerprint, $requestedFingerprint))
                    || (!$storedFingerprint && $legacyMatches)) {
                    $existingOrderNumber = trim((string) ($currentState['order_number'] ?? ''));
                    if ($existingOrderNumber === '') {
                        $existingOrderNumber = trim((string) \App\Models\CommerceOrderAttempt::query()
                            ->where('conversation_id', $conversation->id)
                            ->where('external_order_id', (string) $currentState['order_id'])
                            ->where('status', 'completed')
                            ->latest('id')
                            ->value('order_number'));
                    }
                    Log::info('ProcessAutoReply: matching order already exists, skipping duplicate creation', [
                        'conversation_id' => $conversation->id,
                        'order_exists' => true,
                        'customer_reference_present' => $existingOrderNumber !== '',
                    ]);
                    // This is a duplicate guard, not a successful new order.
                    // Omit order_id so no caller can report a newly placed order.
                    return [
                        'status' => 'already_completed',
                        'existing_order_number' => $existingOrderNumber !== '' ? $existingOrderNumber : null,
                    ];
                }

                // Completed state refers to a different product/store/customer
                // context. Drop its order identity before proceeding.
                foreach (['order_id', 'order_number', 'order_fingerprint', 'order_product_id', 'order_connection_id'] as $key) {
                    unset($currentState[$key]);
                }
                $currentState['status'] = 'in_progress';
                $currentState['confirmation_state'] = 'confirmed';
                $conversation->update(['checkout_state' => $currentState]);
            }

            $commerceContext = app(\App\Services\EcommerceChannelResolver::class)
                ->resolveConversation($conversation, $conversation->bot);
            $commerceChannel = $commerceContext['connection'] ?? null;
            $externalOrderId = null;
            $externalOrderNumber = null;
            $externalSource  = null;

            if (!$commerceChannel || (int) $commerceChannel->business_id !== (int) $conversation->business_id) {
                Log::error('ProcessAutoReply: commerce order blocked because resolved connection is missing or outside business scope', [
                    'conversation_id' => $conversation->id,
                    'business_id' => $conversation->business_id,
                    'resolved_connection_id' => $commerceChannel?->id,
                    'resolved_connection_business_id' => $commerceChannel?->business_id,
                    'provider' => $commerceChannel?->type,
                ]);
                return null;
            }
            Log::info('ProcessAutoReply: commerce order connection authorized', [
                'conversation_id' => $conversation->id,
                'business_id' => $conversation->business_id,
                'connection_id' => $commerceChannel->id,
                'provider' => $commerceChannel->type,
                'connection_status' => $commerceChannel->status,
            ]);

            // Persist an order attempt before contacting the provider. The
            // summary message ID distinguishes a later, intentional checkout
            // of the same product/customer from a retry of this checkout.
            $checkoutFingerprint = $this->orderFingerprint($checkoutState);
            $unresolvedAttempt = \App\Models\CommerceOrderAttempt::query()
                ->where('conversation_id', $conversation->id)
                ->where('connection_id', $commerceChannel->id)
                ->where('checkout_fingerprint', $checkoutFingerprint)
                ->whereIn('status', ['submitting', 'unknown'])
                ->latest('id')
                ->first();
            if ($unresolvedAttempt) {
                Log::warning('ProcessAutoReply: suppressing duplicate order because a matching provider attempt is unresolved', [
                    'conversation_id' => $conversation->id,
                    'attempt_id' => $unresolvedAttempt->id,
                    'attempt_status' => $unresolvedAttempt->status,
                ]);
                return null;
            }
            $summaryMessageId = (string) ($checkoutState['confirmation_summary_message_id']
                ?? $currentState['confirmation_summary_message_id']
                ?? '');
            $idempotencyKey = hash('sha256', implode('|', [
                $conversation->id,
                $commerceChannel->id,
                $checkoutFingerprint,
                $summaryMessageId,
            ]));
            $attempt = \App\Models\CommerceOrderAttempt::firstOrCreate(
                ['idempotency_key' => $idempotencyKey],
                [
                    'business_id' => $conversation->business_id,
                    'conversation_id' => $conversation->id,
                    'connection_id' => $commerceChannel->id,
                    'provider' => (string) $commerceChannel->type,
                    'checkout_fingerprint' => $checkoutFingerprint,
                    'status' => 'pending',
                ],
            );

            if ($attempt->status === 'completed' && $attempt->external_order_id) {
                return [
                    'success' => true,
                    'id' => $attempt->external_order_id,
                    'order_id' => $attempt->external_order_id,
                    'order_number' => $attempt->order_number ?: $attempt->external_order_id,
                    'external_source' => $attempt->provider,
                    'total' => (float) ($checkoutState['product_price'] ?? 0),
                    'status' => 'completed',
                    'currency' => $checkoutState['product_currency'] ?? 'SAR',
                    'has_order' => true,
                    'items' => [[
                        'name' => $checkoutState['product_name'] ?? 'Product',
                        'price' => (float) ($checkoutState['product_price'] ?? 0),
                        'quantity' => 1,
                    ]],
                    'product_name' => $checkoutState['product_name'] ?? 'Product',
                ];
            }

            // `pending` means the process recorded intent but had not yet
            // started the provider request; it is safe to resume under the
            // per-conversation lock. `submitting`/`unknown` remain fail-closed.
            if ($attempt->status === 'failed') {
                $attempt->update(['status' => 'pending', 'external_order_id' => null, 'order_number' => null]);
            }

            // The active conversation connection is authoritative for every
            // order operation; this method never probes other connected stores.
            $sallaChannel = $commerceChannel?->type === 'salla' ? $commerceChannel : null;

            if ($sallaChannel) {
                try {
                    $attempt->update(['status' => 'submitting']);
                    Log::info('ProcessAutoReply: sending order request to Salla', [
                        'conversation_id' => $conversation->id,
                        'connection_id' => $sallaChannel->id,
                        'product_id_present' => !empty($checkoutState['salla_product_id']),
                    ]);
                    $sallaService = new SallaService();
                    $sallaRes = $sallaService->createOrderForChannel($sallaChannel, $checkoutState);

                    if (($sallaRes['status'] ?? null) === 'needs_fields') {
                        $providerMissingFields = array_values(array_unique((array) ($sallaRes['missing_fields'] ?? [])));
                        // Salla's own API validation named the specific
                        // ship_to fields THIS store requires. Persist them so
                        // the next turn asks the customer only for those,
                        // instead of a generic retry/escalation.
                        $knownRequired = is_array($conversation->fresh()->checkout_state)
                            ? (array) ($conversation->fresh()->checkout_state['salla_required_fields'] ?? [])
                            : [];
                        $mergedRequired = array_values(array_diff(
                            array_unique(array_merge($knownRequired, (array) $sallaRes['missing_fields'])),
                            ['geo_coordinates']
                        ));
                        $stateWithRequired = is_array($conversation->fresh()->checkout_state) ? $conversation->fresh()->checkout_state : [];
                        $stateWithRequired['salla_required_fields'] = $mergedRequired;
                        $stateWithRequired['confirmation_state'] = 'collecting_info';
                        $conversation->update(['checkout_state' => $stateWithRequired]);
                        // Salla returned a definitive 422, so this idempotency
                        // attempt is safe to retry after the customer supplies
                        // the newly required fields.
                        $attempt->update(['status' => 'failed']);
                        Log::info('ProcessAutoReply: Salla reported additional required ship_to fields; will ask customer for exactly these', [
                            'conversation_id' => $conversation->id,
                            'required_fields' => $mergedRequired,
                        ]);
                        return [
                            'status' => 'needs_fields',
                            // Keep the current 422 separate from previously
                            // learned store requirements. The caller may clear
                            // only fields Salla rejected in this request.
                            'missing_fields' => $providerMissingFields !== [] ? $providerMissingFields : $mergedRequired,
                        ];
                    }

                    // The provider's immutable API ID is the canonical order
                    // identity. reference_id is the merchant-facing number.
                    $externalOrderId = (string)($sallaRes['data']['id'] ?? $sallaRes['id'] ?? $sallaRes['data']['reference_id'] ?? null);
                    $externalOrderNumber = (string) ($sallaRes['data']['reference_id'] ?? $externalOrderId);
                    if ($externalOrderId) {
                        $externalSource = 'salla';
                        Log::info('ProcessAutoReply: Salla order created successfully via API', [
                            'conversation_id' => $conversation->id,
                            'salla_order_id'  => $externalOrderId,
                        ]);
                    } else {
                        $attempt->update(['status' => 'unknown']);
                        Log::error('SALLA_ORDER_CREATION_FAILED', [
                            'conversation_id' => $conversation->id,
                            'channel_id'      => $sallaChannel->id,
                            'response_keys' => array_keys($sallaRes),
                        ]);
                        return null;
                    }
                } catch (\Exception $e) {
                    // The order.created webhook can arrive while the POST is
                    // still waiting or after the connection timed out. If it
                    // already reconciled this attempt, trust the persisted
                    // provider order ID and continue the normal success path.
                    $reconciledAttempt = $attempt->fresh();
                    if ($reconciledAttempt?->status === 'completed' && $reconciledAttempt->external_order_id) {
                        $externalOrderId = (string) $reconciledAttempt->external_order_id;
                        $externalOrderNumber = (string) ($reconciledAttempt->order_number ?: $externalOrderId);
                        $externalSource = 'salla';
                        Log::info('ProcessAutoReply: Salla webhook reconciled order after submission exception', [
                            'conversation_id' => $conversation->id,
                            'attempt_id' => $attempt->id,
                        ]);
                    } else {
                    // A provider HTTP response confirms rejection. Network
                    // failures stay pending because the provider may have
                    // accepted the request before the connection dropped.
                    $sallaPreflightRejected = str_contains(
                        $e->getMessage(),
                        'Canonical Salla order payload could not be constructed'
                    );
                    $definiteSallaRejection = $e instanceof \App\Exceptions\SallaOrderValidationException
                        || ($e instanceof \App\Exceptions\SallaOrderSubmissionException
                            && $e->isDefinitiveRejection());
                    if ($sallaPreflightRejected || $definiteSallaRejection
                        || str_contains(strtolower($e->getMessage()), 'token expired')
                        || str_contains(strtolower($e->getMessage()), 'scope missing')
                        || str_contains(strtolower($e->getMessage()), 'rate limited')) {
                        $attempt->update(['status' => 'failed']);
                    } elseif ($e instanceof \App\Exceptions\SallaOrderSubmissionException) {
                        $attempt->update(['status' => 'unknown']);
                    }
                    if ($sallaPreflightRejected) {
                        Log::warning('ProcessAutoReply: Salla order rejected before provider request; attempt is safe to retry after checkout data is corrected', [
                            'conversation_id' => $conversation->id,
                            'connection_id' => $sallaChannel->id,
                            'attempt_id' => $attempt->id,
                            'provider_request_sent' => false,
                        ]);
                    }
                    Log::error('SALLA_ORDER_CREATION_FAILED', [
                        'conversation_id' => $conversation->id,
                        'channel_id'      => $sallaChannel->id,
                        'exception_type' => get_class($e),
                        'provider_http_status' => $e instanceof \App\Exceptions\SallaOrderSubmissionException ? $e->httpStatus : null,
                        'provider_request_sent' => $e instanceof \App\Exceptions\SallaOrderSubmissionException,
                        'attempt_status' => $attempt->fresh()->status,
            'message' => \App\Services\OrderCheckoutService::maskSensitive($e->getMessage()),
            'file' => basename($e->getFile()) . ':' . $e->getLine(),
                    ]);
                    // STRICT RULE: DO NOT generate a fake ID or pretend success when Salla fails!
                    return null;
                    }
                }
            }

            // Shopify order creation may run only against the resolved Shopify
            // connection. Unsupported providers fail closed below.
            if (!$externalOrderId && $commerceChannel?->type === 'shopify') {
                $shopifyChannel = $commerceChannel;

                    try {
                        $commerceProduct = \App\Models\Product::query()
                            ->where('business_id', $conversation->business_id)
                            ->where('commerce_channel_id', $shopifyChannel->id)
                            ->where('commerce_external_id', (string) ($checkoutState['commerce_external_id'] ?? ''))
                            ->first();
                        $variants = $commerceProduct?->metadata['variants'] ?? [];
                        $selectedVariantId = $checkoutState['commerce_variant_id'] ?? null;
                        $variantGid = count($variants) === 1
                            ? ($variants[0]['id'] ?? null)
                            : collect($variants)->firstWhere('id', $selectedVariantId)['id'] ?? null;
                        if (!$variantGid || !preg_match('~/ProductVariant/(\d+)$~', (string) $variantGid, $variantMatch)) {
                            Log::error('ProcessAutoReply: Shopify order blocked before API request because variant could not be resolved', [
                                'conversation_id' => $conversation->id,
                                'connection_id' => $shopifyChannel->id,
                                'product_record_found' => (bool) $commerceProduct,
                                'variant_count' => count($variants),
                                'selected_variant_present' => !empty($selectedVariantId),
                            ]);
                            throw new \RuntimeException('The selected Shopify product has no synced variant ID.');
                        }
                        $nameParts = preg_split('/\s+/', trim((string) ($checkoutState['full_name'] ?? '')), 2) ?: [];
                        $shop  = $shopifyChannel->page_id;
                        $token = $shopifyChannel->access_token;
                        $apiBase = "https://{$shop}/admin/api/" . config('services.shopify.api_version', '2026-07');
                        $normalizedPhone = \App\Services\ShopifyOrderPayload::normalizePhone(
                            $checkoutState['phone'] ?? $checkoutState['customer_phone'] ?? null,
                            $checkoutState['country'] ?? $checkoutState['address'] ?? null
                        );
                        $existingCustomerId = null;

                        // Shopify rejects creating a new customer when the phone
                        // already belongs to one. Search this resolved shop and
                        // attach the new order to the exact matching customer.
                        if ($normalizedPhone !== null) {
                            try {
                                $customerLookup = \App\Services\ShopifyHttp::client($token)
                                    ->get("{$apiBase}/customers/search.json", [
                                        'query' => "phone:{$normalizedPhone}",
                                        'fields' => 'id,phone',
                                        'limit' => 10,
                                    ]);
                                $matches = $customerLookup->json('customers', []);
                                if ($customerLookup->successful() && is_array($matches)) {
                                    foreach ($matches as $candidate) {
                                        $candidatePhone = \App\Services\ShopifyOrderPayload::normalizePhone(
                                            $candidate['phone'] ?? null,
                                            $checkoutState['country'] ?? $checkoutState['address'] ?? null
                                        );
                                        if ($candidatePhone === $normalizedPhone && !empty($candidate['id'])) {
                                            $existingCustomerId = (int) $candidate['id'];
                                            break;
                                        }
                                    }
                                } else {
                                    Log::warning('ProcessAutoReply: Shopify customer lookup unavailable; will attempt new customer association', [
                                        'conversation_id' => $conversation->id,
                                        'connection_id' => $shopifyChannel->id,
                                        'http_status' => $customerLookup->status(),
                                        'error_fields' => is_array($customerLookup->json('errors'))
                                            ? array_slice(array_map('strval', array_keys($customerLookup->json('errors'))), 0, 10)
                                            : [],
                                    ]);
                                }
                                Log::info('ProcessAutoReply: Shopify customer lookup completed', [
                                    'conversation_id' => $conversation->id,
                                    'connection_id' => $shopifyChannel->id,
                                    'lookup_succeeded' => $customerLookup->successful(),
                                    'candidate_count' => is_array($matches) ? count($matches) : 0,
                                    'existing_customer_matched' => $existingCustomerId !== null,
                                ]);
                            } catch (\Throwable $lookupException) {
                                Log::warning('ProcessAutoReply: Shopify customer lookup threw; will attempt new customer association', [
                                    'conversation_id' => $conversation->id,
                                    'connection_id' => $shopifyChannel->id,
                                    'exception_type' => get_class($lookupException),
                                ]);
                            }
                        }
                        Log::info('ProcessAutoReply: sending order request to Shopify', [
                            'conversation_id' => $conversation->id,
                            'connection_id' => $shopifyChannel->id,
                            'shop_domain' => $shop,
                            'api_version' => config('services.shopify.api_version', '2026-07'),
                            'variant_resolved' => true,
                            'quantity' => (int) ($checkoutState['quantity'] ?? 1),
                            'existing_customer_matched' => $existingCustomerId !== null,
                        ]);
                        $freeformShippingAddress = (string) ($checkoutState['address'] ?? $checkoutState['customer_address'] ?? '');
                        $inferredShippingLocation = app(\App\Services\OrderCheckoutService::class)->resolveDeliveryLocation(
                            $freeformShippingAddress,
                            (string) ($checkoutState['phone'] ?? $checkoutState['customer_phone'] ?? '')
                        );
                        if (empty($checkoutState['city']) && !empty($inferredShippingLocation['city'])) {
                            $checkoutState['city'] = $inferredShippingLocation['city'];
                        }
                        if (empty($checkoutState['country']) && !empty($inferredShippingLocation['country'])) {
                            $checkoutState['country'] = $inferredShippingLocation['country'];
                        }
                        $orderPayload = \App\Services\ShopifyOrderPayload::build(
                            (int) $variantMatch[1],
                            (int) ($checkoutState['quantity'] ?? 1),
                            $checkoutState,
                            $existingCustomerId
                        );
                        Log::info('ProcessAutoReply: Shopify shipping address payload prepared', [
                            'conversation_id' => $conversation->id,
                            'connection_id' => $shopifyChannel->id,
                            'address1_present' => !empty($orderPayload['order']['shipping_address']['address1'] ?? null),
                            'first_name_present' => !empty($orderPayload['order']['shipping_address']['first_name'] ?? null),
                            'last_name_present' => !empty($orderPayload['order']['shipping_address']['last_name'] ?? null),
                            'city_present' => !empty($orderPayload['order']['shipping_address']['city'] ?? null),
                            'country_present' => !empty($orderPayload['order']['shipping_address']['country'] ?? null),
                        ]);
                        $response = \App\Services\ShopifyHttp::client($token)->post("{$apiBase}/orders.json", $orderPayload);

                        // If customer lookup was unavailable and Shopify rejects
                        // inline customer creation because this contact already
                        // exists, retry once as an order attached to the supplied
                        // shipping address. The 422 confirms no order was created.
                        $customerErrors = $response->json('errors.customer', []);
                        $duplicateCustomerContact = $response->status() === 422
                            && is_array($customerErrors)
                            && collect($customerErrors)->flatten()->contains(
                                fn ($error) => is_string($error) && str_contains(strtolower($error), 'already been taken')
                            );
                        if ($duplicateCustomerContact && $existingCustomerId === null) {
                            Log::warning('ProcessAutoReply: Shopify customer already exists but could not be matched; retrying order without creating a duplicate customer', [
                                'conversation_id' => $conversation->id,
                                'connection_id' => $shopifyChannel->id,
                                'error_fields' => array_slice(array_map('strval', array_keys($customerErrors)), 0, 10),
                            ]);
                            $guestOrderPayload = \App\Services\ShopifyOrderPayload::build(
                                (int) $variantMatch[1],
                                (int) ($checkoutState['quantity'] ?? 1),
                                $checkoutState,
                                null,
                                false
                            );
                            $response = \App\Services\ShopifyHttp::client($token)->post("{$apiBase}/orders.json", $guestOrderPayload);
                            Log::info('ProcessAutoReply: Shopify duplicate-customer fallback response received', [
                                'conversation_id' => $conversation->id,
                                'connection_id' => $shopifyChannel->id,
                                'http_status' => $response->status(),
                                'successful' => $response->successful(),
                                'order_id_present' => !empty($response->json('order.id')),
                            ]);
                        }
                        Log::info('ProcessAutoReply: Shopify order API response received', [
                            'conversation_id' => $conversation->id,
                            'connection_id' => $shopifyChannel->id,
                            'http_status' => $response->status(),
                            'successful' => $response->successful(),
                            'order_id_present' => !empty($response->json('order.id')),
                            'shipping_address_returned' => is_array($response->json('order.shipping_address'))
                                && !empty($response->json('order.shipping_address.address1')),
                            'error_fields' => is_array($response->json('errors'))
                                ? array_slice(array_map('strval', array_keys($response->json('errors'))), 0, 20)
                                : [],
                        ]);

                        if ($response->successful()) {
                            $resJson = $response->json();
                            $externalOrderId = (string)($resJson['order']['id'] ?? '');
                            $externalOrderNumber = (string)($resJson['order']['name'] ?? $resJson['order']['order_number'] ?? '');
                            if ($externalOrderId) {
                                $externalSource = 'shopify';
                                Log::info('ProcessAutoReply: Shopify order created successfully via API', [
                                    'conversation_id'  => $conversation->id,
                                    'shopify_order_id' => $externalOrderId,
                                ]);
                            }
                        } else {
                            $attempt->update(['status' => 'failed']);
                            Log::error('SHOPIFY_ORDER_CREATION_FAILED', [
                                'conversation_id' => $conversation->id,
                                'status'          => $response->status(),
                                ...$this->sanitizedShopifyErrorDetails($response->json('errors'), $checkoutState),
                            ]);
                            if (in_array($response->status(), [401, 403], true)) {
                                // The store token is invalid or revoked: no order can be created until it is reconnected.
                                Log::critical('SHOPIFY_AUTH_FAILED', [
                                    'conversation_id' => $conversation->id,
                                    'connection_id'   => $commerceChannel->id,
                                    'status'          => $response->status(),
                                ]);
                                app(\App\Services\HandoffTaskService::class)->openForConversation($conversation, 'shopify_auth_failed', 'high');
                            }
                            return null;
                        }
                    } catch (\Exception $e) {
                        Log::error('SHOPIFY_ORDER_CREATION_FAILED', [
                            'conversation_id' => $conversation->id,
                            'connection_id' => $shopifyChannel->id,
                            'exception_type' => get_class($e),
                            'exception_code' => is_numeric($e->getCode()) ? (int) $e->getCode() : null,
                            'provider_request_started' => true,
                        ]);
                        return null;
                }
            }

            if (!$externalOrderId && $commerceChannel?->type === 'woocommerce') {
                try {
                    Log::info('ProcessAutoReply: sending order request to WooCommerce', [
                        'conversation_id' => $conversation->id,
                        'connection_id' => $commerceChannel->id,
                        'product_id_present' => !empty($checkoutState['commerce_external_id']),
                    ]);
                    $wooProduct = \App\Models\Product::query()
                        ->where('business_id', $conversation->business_id)
                        ->where('commerce_channel_id', $commerceChannel->id)
                        ->where('commerce_external_id', (string) ($checkoutState['commerce_external_id'] ?? ''))
                        ->first();
                    $checkoutState['commerce_product_metadata'] = $wooProduct?->metadata ?? [];
                    $wooResponse = app(\App\Services\WooCommerceService::class)
                        ->createOrderForChannel($commerceChannel, $checkoutState);
                    $wooOrder = $wooResponse['data'] ?? [];
                    $externalOrderId = (string) ($wooOrder['id'] ?? '');
                    Log::info('ProcessAutoReply: WooCommerce order response processed', [
                        'conversation_id' => $conversation->id,
                        'connection_id' => $commerceChannel->id,
                        'order_id_present' => $externalOrderId !== '',
                    ]);
                    if ($externalOrderId !== '') {
                        $externalSource = 'woocommerce';
                    }
                } catch (\Throwable $e) {
                    // Classify exception to determine whether the provider definitely did not create an order.
                    // RuntimeException throw points in WooCommerceService all fire before or during request
                    // construction, or from explicit HTTP-rejection/parse checks — treat them as definite failures.
                    // Connection/timeout exceptions are ambiguous (provider may have accepted the request).
                    $isDefiniteFailure = ($e instanceof \RuntimeException);
                    if ($isDefiniteFailure) {
                        $attempt->update(['status' => 'failed']);
                    }
                    // Sanitize the message — strip any embedded secrets, tokens, or customer data.
                    $safeMessage = \App\Services\OrderCheckoutService::maskSensitive($e->getMessage());
                    // Extract safe diagnostic fields from HTTP exceptions where available.
                    $httpStatus = null;
                    $providerCode = null;
                    if (method_exists($e, 'response') && $e->response !== null) {
                        try {
                            $httpStatus = $e->response->status();
                            $providerCode = $e->response->json('code');
                        } catch (\Throwable) {
                        }
                    }
                    Log::error('WOOCOMMERCE_ORDER_CREATION_FAILED', [
                        'conversation_id'    => $conversation->id,
                        'messaging_channel_id' => $channel->id,      // Instagram channel (16)
                        'connection_id'      => $commerceChannel->id, // WooCommerce connection (9)
                        'provider'           => 'woocommerce',
                        'local_product_id'   => $wooProduct?->id,
                        'external_product_id' => $checkoutState['commerce_external_id'] ?? null,
                        'exception_type'     => get_class($e),
                        'message'            => $safeMessage,
                        'file'               => basename($e->getFile()) . ':' . $e->getLine(),
                        'http_status'        => $httpStatus,
                        'provider_code'      => is_string($providerCode) ? $providerCode : null,
                        'attempt_marked_failed' => $isDefiniteFailure,
                    ]);
                    // If the failure is specifically a missing product variation, this is missing
                    // customer input — not a generic technical failure. Signal it via a dedicated
                    // sentinel so the caller can prompt the customer for their selection instead
                    // of escalating to a human.
                    if ($e instanceof \RuntimeException
                        && str_contains($e->getMessage(), 'variation must be selected')) {
                        return ['__needs_variation' => true, 'product_metadata' => $wooProduct?->metadata ?? []];
                    }
                    return null;
                }
            }

            // STRICT RULE: If no external commerce channel was connected or order creation did not return 2xx success:
            if (!$externalOrderId) {
                Log::warning('ProcessAutoReply: no external order created by commerce platform — aborting order completion', [
                    'conversation_id' => $conversation->id,
                ]);
                return null;
            }

            // Store the provider result before returning to the checkout
            // caller, which persists conversation state separately. If that
            // later write fails, the next execution reuses this real order.
            $attempt->update([
                'status' => 'completed',
                'external_order_id' => $externalOrderId,
                'order_number' => $externalOrderNumber ?: $externalOrderId,
            ]);

            $checkoutLineItems = is_array($checkoutState['salla_line_items'] ?? null) ? $checkoutState['salla_line_items'] : [];
            $orderTotal = $checkoutLineItems !== []
                ? array_sum(array_map(static fn (array $item): float =>
                    max(0, (float) ($item['price'] ?? 0)) * max(1, (int) ($item['quantity'] ?? 1)), $checkoutLineItems))
                : (float) (!empty($checkoutState['salla_variant_price']) ? $checkoutState['salla_variant_price'] : ($checkoutState['product_price'] ?? 0));
            $orderItems = $checkoutLineItems !== []
                ? array_map(static fn (array $item): array => [
                    'name' => trim((string) ($item['product_name'] ?? $item['name'] ?? 'Product')),
                    'price' => (float) ($item['price'] ?? 0),
                    'quantity' => max(1, (int) ($item['quantity'] ?? 1)),
                    'options' => $item['option_titles'] ?? $item['salla_option_titles'] ?? [],
                ], $checkoutLineItems)
                : [[
                    'name' => $checkoutState['product_name'] ?? 'Product',
                    'price' => $orderTotal,
                    'quantity' => 1,
                ]];
            $orderData  = [
                'id'              => $externalOrderId,
                'order_id'        => $externalOrderId,
                'order_number'    => $externalOrderNumber ?: $externalOrderId,
                'external_source' => $externalSource,
                'total'           => $orderTotal,
                'status'          => 'completed',
                'currency'        => $checkoutState['product_currency'] ?? 'SAR',
                'has_order'       => true,
                'items'           => $orderItems,
                'product_name' => $checkoutState['product_name'] ?? 'Product',
                'customer'     => [
                    'name'    => $checkoutState['full_name'] ?? $conversation->sender_name,
                    'phone'   => $checkoutState['phone'] ?? $checkoutState['customer_phone'] ?? null,
                    'address' => $checkoutState['address'] ?? null,
                ],
                'completed_at' => now()->toISOString(),
            ];

            Log::info('ORDER_CREATED', [
                'conversation_id' => $conversation->id,
                'order_id'        => $externalOrderId,
                'source'          => $externalSource,
                'total'           => $orderTotal,
            ]);

            Log::info('ORDER_SEQUENCE_TRIGGER_START', [
                'conversation_id' => $conversation->id,
                'order_id'        => $externalOrderId,
            ]);

            try {
                // Ensure channel relationship is loaded for business_id fallback in SequenceTriggerService
                if (!$conversation->relationLoaded('channel')) {
                    $conversation->load('channel');
                }
                $sequenceTriggerService = app(SequenceTriggerService::class);
                $sequenceTriggerService->checkAndEnrollForOrderCreated($conversation, $orderData);
            } catch (\Exception $e) {
                Log::error('ORDER_SEQUENCE_TRIGGER_ERROR', [
                    'conversation_id' => $conversation->id,
                    'exception_type' => get_class($e),
                ]);
            }

            return $orderData;

        } finally {
            $lock->release();
        }
    }

    /** Stable per-checkout identity used to scope order idempotency. */
    private function orderFingerprint(array $state): string
    {
        $identity = [
            (string) ($state['commerce_channel_id'] ?? ''),
            (string) ($state['commerce_external_id'] ?? $state['salla_product_id'] ?? ''),
            (string) ($state['commerce_variant_id'] ?? ''),
            json_encode($state['salla_options'] ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '',
            preg_replace('/\D+/', '', (string) ($state['phone'] ?? $state['customer_phone'] ?? '')),
            mb_strtolower(trim((string) ($state['address'] ?? $state['customer_address'] ?? ''))),
        ];
        // Preserve fingerprints already persisted by the single-item checkout
        // flow. Add the multi-line identity only when a cart actually exists.
        if (!empty($state['salla_line_items']) && is_array($state['salla_line_items'])) {
            $identity[] = json_encode($state['salla_line_items'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '';
        }

        return hash('sha256', implode('|', $identity));
    }

    /** Fields shown to a customer in the confirmation summary. */
    private function orderSummaryFingerprint(array $state): string
    {
        $summaryFields = [
            'commerce_channel_id', 'commerce_external_id', 'salla_product_id',
            'product_name', 'product_price', 'product_currency', 'commerce_variant_id',
            'commerce_variant_title', 'salla_options', 'salla_option_titles', 'salla_line_items',
            'full_name', 'customer_name', 'phone', 'customer_phone', 'address', 'customer_address',
        ];

        $summary = array_intersect_key($state, array_flip($summaryFields));
        if (is_array($state['salla_shipping_quote'] ?? null)) {
            $quote = $state['salla_shipping_quote'];
            $summary['salla_shipping_quote'] = array_intersect_key($quote, array_flip([
                'company_id', 'shipping_amount', 'cod_service_amount', 'currency', 'city_id', 'address_hash',
            ]));
        }
        ksort($summary);
        return hash('sha256', json_encode($summary, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '');
    }

    /** Build the exact summary required before an explicit order confirmation. */
    private function buildOrderConfirmationSummary(array $checkoutState, ?Channel $commerceChannel): string
    {
        $lineItems = is_array($checkoutState['salla_line_items'] ?? null) ? $checkoutState['salla_line_items'] : [];
        if ($lineItems !== []) {
            $productName = implode('; ', array_map(static function (array $item): string {
                $name = trim((string) ($item['product_name'] ?? $item['name'] ?? 'Product'));
                $quantity = max(1, (int) ($item['quantity'] ?? 1));
                return $quantity > 1 ? "{$name} × {$quantity}" : $name;
            }, $lineItems));
            $price = array_sum(array_map(static fn (array $item): float =>
                max(0, (float) ($item['price'] ?? 0)) * max(1, (int) ($item['quantity'] ?? 1)), $lineItems));
        } else {
            $productName = trim((string) ($checkoutState['product_name'] ?? 'Selected product'));
            // The price must match what Salla will charge. Once an option (size, colour)
            // is chosen, Salla prices the order by that variant, so use the variant price.
            // Never fall back to the base or first-variant product price for a chosen option.
            $hasChosenOptions = !empty($checkoutState['salla_options']) || !empty($checkoutState['salla_option_titles']);
            $price = !empty($checkoutState['salla_variant_price'])
                ? $checkoutState['salla_variant_price']
                : (!$hasChosenOptions ? ($checkoutState['product_price'] ?? $checkoutState['price'] ?? null) : null);
        }
        $currency = trim((string) ($checkoutState['product_currency'] ?? $checkoutState['currency'] ?? ''));
        $priceText = $price !== null && $price !== '' ? trim((string) $price . ' ' . $currency) : 'Not available';
        $shippingQuote = is_array($checkoutState['salla_shipping_quote'] ?? null) ? $checkoutState['salla_shipping_quote'] : null;
        $showShippingQuote = $commerceChannel?->type === 'salla' && $shippingQuote !== null;
        $shippingAmount = (float) ($shippingQuote['shipping_amount'] ?? 0);
        $codFee = (float) ($shippingQuote['cod_service_amount'] ?? 0);
        $estimatedTotal = $price !== null && $price !== '' ? (float) $price + $shippingAmount + $codFee : null;
        $shippingText = number_format($shippingAmount, 2, '.', '') . ' ' . ($shippingQuote['currency'] ?? ($currency ?: 'SAR'));
        $codFeeText = number_format($codFee, 2, '.', '') . ' ' . ($shippingQuote['currency'] ?? ($currency ?: 'SAR'));
        $totalText = $estimatedTotal !== null
            ? number_format($estimatedTotal, 2, '.', '') . ' ' . ($shippingQuote['currency'] ?? ($currency ?: 'SAR'))
            : 'Not available';
        $storeName = trim((string) ($commerceChannel?->page_name ?? '')) ?: 'our store';
        $phone = $checkoutState['phone'] ?? $checkoutState['customer_phone'] ?? null;
        $isArabic = ($checkoutState['checkout_language'] ?? null) === 'arabic';
        if ($lineItems !== []) {
            $optionGroups = array_values(array_filter(array_map(static function (array $item): string {
                $titles = $item['option_titles'] ?? $item['salla_option_titles'] ?? [];
                return implode(', ', array_values(array_unique(array_map('strval', (array) $titles))));
            }, $lineItems)));
            $optionsText = $optionGroups !== [] ? ($isArabic ? 'الخيارات: ' : 'Options: ') . implode('; ', $optionGroups) . "\n" : '';
        } else {
            $titles = array_values(array_unique(array_map('strval', (array) ($checkoutState['salla_option_titles'] ?? []))));
            $optionsText = $titles !== [] ? ($isArabic ? 'الخيارات: ' : 'Options: ') . implode(', ', $titles) . "\n" : '';
        }

        if ($isArabic) {
            return "راجع ملخص طلبك:\n"
                . "المنتج: {$productName}\n"
                . (!empty($checkoutState['commerce_variant_title']) ? 'الخيار: ' . trim((string) $checkoutState['commerce_variant_title']) . "\n" : '')
                . $optionsText
                . "السعر: {$priceText}\n"
                . ($showShippingQuote ? "طريقة الدفع: الدفع عند الاستلام\nالشحن: {$shippingText}\nرسوم الدفع عند الاستلام: {$codFeeText}\nالإجمالي التقديري: {$totalText}\n" : '')
                . "المتجر: {$storeName}\n"
                . 'الاسم: ' . trim((string) ($checkoutState['full_name'] ?? '')) . "\n"
                . 'رقم الجوال: ' . (\App\Services\OrderCheckoutService::isValidPhone($phone) ? trim((string) $phone) : 'غير متوفر') . "\n"
                . 'عنوان التوصيل: ' . trim((string) ($checkoutState['address'] ?? $checkoutState['customer_address'] ?? '')) . "\n"
                . (!empty($checkoutState['building_number']) ? 'رقم المبنى: ' . trim((string) $checkoutState['building_number']) . "\n" : '')
                . (!empty($checkoutState['additional_number']) ? 'الرقم الإضافي: ' . trim((string) $checkoutState['additional_number']) . "\n" : '')
                . "\n"
                . ($commerceChannel?->type === 'salla' && !$showShippingQuote
                    ? 'تعذر تأكيد رسوم التوصيل الآن؛ سيؤكد فريق المتجر الإجمالي قبل إتمام الطلب.'
                    : 'أرسل «تأكيد الطلب» لإتمامه، أو «إلغاء» لإيقافه.');
        }

        return "Please review your order summary:\n"
            . "Product: {$productName}\n"
            . (!empty($checkoutState['commerce_variant_title']) ? 'Variant: ' . trim((string) $checkoutState['commerce_variant_title']) . "\n" : '')
            . $optionsText
            . "Price: {$priceText}\n"
            . ($showShippingQuote ? "Payment: Cash on delivery\nShipping: {$shippingText}\nCOD fee: {$codFeeText}\nEstimated total: {$totalText}\n" : '')
            . "Store: {$storeName}\n"
            . 'Name: ' . trim((string) ($checkoutState['full_name'] ?? '')) . "\n"
            . 'Phone: ' . (\App\Services\OrderCheckoutService::isValidPhone($phone) ? trim((string) $phone) : 'not provided yet') . "\n"
            . 'Delivery address: ' . trim((string) ($checkoutState['address'] ?? $checkoutState['customer_address'] ?? '')) . "\n"
            . (!empty($checkoutState['building_number']) ? 'Building number: ' . trim((string) $checkoutState['building_number']) . "\n" : '')
            . (!empty($checkoutState['additional_number']) ? 'Additional number: ' . trim((string) $checkoutState['additional_number']) . "\n" : '')
            . "\n"
            . ($commerceChannel?->type === 'salla' && !$showShippingQuote
                ? 'Delivery cost could not be confirmed yet. The store team will confirm the total before placing the order.'
                : 'Reply “confirm order” to place it, or “cancel” to stop.');
    }

    /** Keep Shopify's useful validation details in logs without exposing checkout PII. */
    private function sanitizedShopifyErrorDetails(mixed $errors, array $checkoutState): array
    {
        if ($errors === null) {
            return ['error_fields' => [], 'error_summary' => null];
        }

        $fields = is_array($errors) ? array_map('strval', array_keys($errors)) : [];
        $summary = json_encode($errors, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $summary = is_string($summary) ? $summary : '';

        foreach (['full_name', 'customer_name', 'phone', 'customer_phone', 'address', 'email', 'customer_email', 'postal_code'] as $key) {
            $sensitiveValue = trim((string) ($checkoutState[$key] ?? ''));
            if ($sensitiveValue !== '') {
                $summary = str_ireplace($sensitiveValue, '[redacted]', $summary);
            }
        }

        $summary = preg_replace('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', '[redacted-email]', $summary) ?? '';
        $summary = preg_replace('/(?<!\w)\+?[0-9][0-9().\s-]{6,}[0-9](?!\w)/', '[redacted-number]', $summary) ?? '';

        return [
            'error_fields' => array_slice($fields, 0, 20),
            'error_summary' => substr($summary, 0, 800),
        ];
    }

    private function hasValidOrderSummaryForConfirmation(Conversation $conversation, Message $incoming, array $state): bool
    {
        $shouldLogValidation = OrderCheckoutService::isExplicitOrderConfirmation((string) $incoming->content)
            || in_array($state['confirmation_state'] ?? null, ['confirmation_pending', 'confirmed'], true);
        $summaryId = (int) ($state['confirmation_summary_message_id'] ?? 0);
        if ($summaryId < 1 || empty($state['confirmation_summary_fingerprint'])) {
            if ($shouldLogValidation) Log::info('ProcessAutoReply: order summary validation failed', [
                'conversation_id' => $conversation->id,
                'incoming_message_id' => $incoming->id,
                'summary_message_id_present' => $summaryId > 0,
                'summary_fingerprint_present' => !empty($state['confirmation_summary_fingerprint']),
                'reason' => $summaryId < 1 ? 'summary_message_id_missing' : 'summary_fingerprint_missing',
            ]);
            return false;
        }

        if (!hash_equals((string) $state['confirmation_summary_fingerprint'], $this->orderFingerprint($state))) {
            if ($shouldLogValidation) Log::warning('ProcessAutoReply: order summary fingerprint mismatch', [
                'conversation_id' => $conversation->id,
                'incoming_message_id' => $incoming->id,
                'summary_message_id' => $summaryId,
                'stored_fingerprint_matches_current_checkout' => false,
                'reason' => 'checkout_state_changed_since_summary',
            ]);
            return false;
        }

        $summary = Message::query()
            ->where('conversation_id', $conversation->id)
            ->whereKey($summaryId)
            ->where('direction', 'outbound')
            ->where('send_status', 'sent')
            ->first();
        if (!$summary || empty($summary->metadata['order_summary'])) {
            if ($shouldLogValidation) Log::info('ProcessAutoReply: order summary validation failed', [
                'conversation_id' => $conversation->id,
                'incoming_message_id' => $incoming->id,
                'summary_message_id' => $summaryId,
                'summary_message_found_and_sent' => (bool) $summary,
                'summary_marker_present' => (bool) ($summary && !empty($summary->metadata['order_summary'])),
                'summary_send_status' => $summary?->send_status,
                'reason' => !$summary ? 'summary_not_found_or_not_sent' : 'summary_marker_missing',
            ]);
            return false;
        }

        $quotedMessageId = (string) ($incoming->metadata['quoted_message_id'] ?? '');
        if ($quotedMessageId !== '' && in_array($quotedMessageId, [
            (string) $summary->id,
            (string) ($summary->metadata['platform_message_id'] ?? ''),
            (string) ($summary->whatsapp_message_id ?? ''),
            (string) ($summary->gmail_message_id ?? ''),
        ], true)) {
            if ($shouldLogValidation) Log::info('ProcessAutoReply: order summary validated by explicit reply reference', [
                'conversation_id' => $conversation->id,
                'incoming_message_id' => $incoming->id,
                'summary_message_id' => $summary->id,
            ]);
            return true;
        }

        // If no explicit quote exists, the summary must be the immediately
        // preceding conversation message, with no intervening turns.
        // Only customer messages and merchant-typed replies count as intervening turns.
        // The bot's own follow-up text must not invalidate a confirmation of its summary.
        $hasInterveningTurn = Message::query()
            ->where('conversation_id', $conversation->id)
            ->where('id', '>', $summary->id)
            ->where('id', '<', $incoming->id)
            ->where(function ($q) {
                $q->where('direction', 'inbound')
                    ->orWhere(fn ($r) => $r->where('direction', 'outbound')
                        ->where(fn ($s) => $s->whereNull('source')->orWhere('source', '!=', 'ai')));
            })
            ->exists();
        if ($shouldLogValidation) Log::info('ProcessAutoReply: order summary validation result', [
            'conversation_id' => $conversation->id,
            'incoming_message_id' => $incoming->id,
            'summary_message_id' => $summary->id,
            'summary_sent' => $summary->send_status === 'sent',
            'has_intervening_customer_or_merchant_turn' => $hasInterveningTurn,
            'valid' => !$hasInterveningTurn,
            'reason' => $hasInterveningTurn ? 'intervening_customer_or_merchant_message' : 'latest_sent_summary_is_valid',
        ]);

        return !$hasInterveningTurn;
    }

    /**
     * Cancel a not-yet-submitted checkout, or hand off cancellation of an
     * already placed order. Returns true when this inbound message was handled.
     */
    private function handleExplicitCheckoutCancellation(
        Channel $channel,
        Conversation $conversation,
        Message $incoming,
        mixed $checkoutState,
    ): bool {
        if ($incoming->direction !== 'inbound' || !is_array($checkoutState)
            || !$this->isExplicitCheckoutCancellation($incoming->content, (int) $conversation->business_id)) {
            return false;
        }

        $hasCheckout = !empty($checkoutState['salla_product_id'])
            || !empty($checkoutState['commerce_external_id'])
            || !empty($checkoutState['product_name'])
            || !empty($checkoutState['order_id']);
        if (!$hasCheckout) {
            return false;
        }

        $orderAlreadyPlaced = !empty($checkoutState['order_id'])
            || ($checkoutState['confirmation_state'] ?? null) === 'order_placed';
        if ($orderAlreadyPlaced) {
            $checkoutState['cancellation_requested_at'] = now()->toIso8601String();
            $conversation->update([
                'checkout_state' => $checkoutState,
                'requires_human' => true,
                'ai_enabled' => false,
                'status' => 'pending_human',
                'escalated_at' => now(),
                'escalation_reason' => 'order_cancellation_requested',
            ]);
            app(\App\Services\HandoffTaskService::class)->openForConversation(
                $conversation,
                'order_cancellation_requested',
                'high'
            );

            $reply = preg_match('/\p{Arabic}/u', $incoming->content)
                ? 'تم تسجيل طلب إلغاء الطلب، لكن الطلب تم إنشاؤه بالفعل ولم يتم إلغاؤه بعد. أحلت طلبك إلى أحد أعضاء الفريق لمساعدتك.'
                : 'Your order has already been placed, so it has not been cancelled yet. I’ve asked a team member to help with your cancellation request.';
            $source = 'order_cancellation_handoff';
        } else {
            $conversation->update(['checkout_state' => null]);
            $reply = preg_match('/\p{Arabic}/u', $incoming->content)
                ? 'تم إلغاء عملية الطلب، ولم يتم إرسال أي طلب إلى المتجر. يمكنك بدء طلب جديد في أي وقت.'
                : 'Checkout cancelled. No order was submitted. You can start a new order whenever you’re ready.';
            $source = 'checkout_cancelled';
        }

        $replyMessage = Message::create([
            'conversation_id' => $conversation->id,
            'content' => $reply,
            'direction' => 'outbound',
            'status' => 'auto',
            'is_ai' => false,
            'source' => $source,
            'send_status' => 'pending',
            'metadata' => ['checkout_cancellation' => true, 'order_already_placed' => $orderAlreadyPlaced],
        ]);

        if ($channel->user_id) {
            broadcast(new \App\Events\MessageReceived($replyMessage, $conversation, $channel->user_id));
        }
        $this->sendReply($channel, $conversation, $replyMessage);

        Log::info('ProcessAutoReply: explicit checkout cancellation handled before commerce actions', [
            'conversation_id' => $conversation->id,
            'order_already_placed' => $orderAlreadyPlaced,
            'source' => $source,
        ]);

        return true;
    }

    private function isExplicitCheckoutCancellation(string $message, ?int $businessId = null): bool
    {
        $message = mb_strtolower(trim($message));
        $pattern =
            '/^(?:(?:please|kindly)\s+)?(?:cancel|stop)(?:\s+(?:(?:my|the)\s+)?(?:order|checkout))?[.!?\s]*$'
                . '|^(?:i\s+)?(?:want|need)\s+to\s+cancel\s+(?:(?:my|the)\s+)?(?:order|checkout)[.!?\s]*$'
                . '|^(?:i\s+)?(?:changed\s+my\s+mind|do\s+not\s+proceed|don.t\s+proceed)(?:\s+with\s+(?:the\s+)?order)?[.!?\s]*$'
                . '|^(?:من\s+فضلك\s+)?(?:إلغاء|الغاء|ألغِ|الغي)(?:\s+(?:الطلب|طلبي|عملية\s+الطلب))?[.!؟\s]*$/u';
        if ($businessId && \App\Models\FeatureFlag::isEnabled('salla_workflow_guardrails', $businessId)) {
            $pattern = str_replace(
                '$/u',
                '|^(?:أريد|اريد|عايز|عاوز|أبغى|ابغى)\s+(?:إلغاء|الغاء|ألغي|الغي)\s+(?:الطلب|طلبي|الأوردر|الاوردر|ده|دا)[.!؟\s]*'
                    . '|^(?:خلاص\s+)?(?:لا\s+)?(?:الغِ|الغى|الغاء|إلغاء|ألغي|الغي)(?:\s+(?:الطلب|طلبي|الأوردر|الاوردر|ده|دا))?$/u',
                $pattern
            );
        }
        return (bool) preg_match($pattern, $message);
    }

    /** Notify the business once for each inbound message skipped for lack of AI credits. */
    private function notifyInsufficientAiCredits(
        \App\Models\BusinessProfile $business,
        \App\Models\Conversation $conversation,
        Message $message,
    ): void {
        if (!$business->user_id) {
            return;
        }

        $alreadyNotified = \App\Models\Notification::query()
            ->where('user_id', $business->user_id)
            ->where('type', 'ai_credits')
            ->where('data->message_id', (int) $message->id)
            ->exists();
        if ($alreadyNotified) {
            return;
        }

        \App\Models\Notification::query()->create([
            'user_id' => $business->user_id,
            'type' => 'ai_credits',
            'title' => 'AI reply skipped: insufficient credits',
            'message' => 'A customer message was saved, but the AI could not reply because this business has insufficient AI credits. Buy credits or wait for the allowance to renew.',
            'data' => [
                'business_id' => (int) $business->id,
                'conversation_id' => (int) $conversation->id,
                'message_id' => (int) $message->id,
                'reason' => 'insufficient_credits',
            ],
            'action_url' => '/dashboard/billing',
            'is_read' => false,
        ]);
    }

    /** True only when the order's customer phone matches one of the sender's phones (last 9 digits). */
    private function orderBelongsToSender(array $order, array $senderPhones): bool
    {
        $orderPhone = preg_replace('/[^0-9]/', '', (string) ($order['customer']['mobile'] ?? $order['customer']['phone'] ?? ''));
        if ($orderPhone === '') {
            return false;
        }
        $orderLast9 = substr($orderPhone, -9);
        foreach ($senderPhones as $phone) {
            $digits = preg_replace('/[^0-9]/', '', (string) $phone);
            if ($digits !== '' && substr($digits, -9) === $orderLast9) {
                return true;
            }
        }
        return false;
    }

    /** Digits of the sender ID, only when the channel's sender ID is itself a phone number (WhatsApp). */
    private function senderPhoneDigits(\App\Models\Conversation $conversation): string
    {
        if (($conversation->channel?->type ?? null) !== 'whatsapp') {
            return '';
        }

        return preg_replace('/[^0-9]/', '', (string) ($conversation->sender_id ?? '')) ?? '';
    }
}
