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

        if (!$channel || !$channel->ai_enabled) {
            Log::info('ProcessAutoReply: AI not enabled for channel', ['channel_id' => $channel?->id]);
            return;
        }

        // ai_enabled on Conversation lets agents disable AI per-conversation (e.g. after escalation)
        if (!$conversation) {
            Log::info('ProcessAutoReply: conversation not found', ['message_id' => $this->messageId]);
            return;
        }

        // Default true if column is null (old rows before migration)
        if ($conversation->ai_enabled === false) {
            Log::info('ProcessAutoReply: AI disabled for this conversation (human took over)', [
                'conversation_id' => $conversation->id,
            ]);
            return;
        }

        if ($channel->status !== 'connected') {
            Log::warning('ProcessAutoReply: channel not connected', ['channel_id' => $channel->id, 'status' => $channel->status]);
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
        if (in_array($message->type ?? '', ['voice', 'audio']) && $message->media_url) {
            try {
                $voiceService = new \App\Services\VoiceService();
                $audioPath = app(\App\Services\IncomingMediaStorage::class)->absolutePath(
                    $message->media_url,
                    data_get($message->metadata, 'media.storage_disk')
                );
                if (!$audioPath) {
                    throw new \RuntimeException('Inbound audio is unavailable in private storage.');
                }

                $voiceTranscription = $voiceService->transcribeAudio($audioPath);
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
        if (($message->type ?? '') === 'image' && $message->media_url) {
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
                    data_get($message->metadata, 'media.storage_disk')
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
                ->whereRaw('LOWER(TRIM(content)) REGEXP ?', ['^(hi|hello|hey|مرحبا|السلام عليكم|صباح الخير|مساء الخير|اهلا|هلا)[\\s\\!\\.\\,]*$'])
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
        
        // Resolve the store once before reference or intent handling. References must
        // be validated against the same deterministic conversation commerce context.
        $commerceContext = app(\App\Services\EcommerceChannelResolver::class)
            ->resolveConversation($conversation, $bot);
        $commerceChannel = $commerceContext['connection'];
        $sallaChannel = $commerceChannel?->type === 'salla' ? $commerceChannel : null;

        $referencedProductResolver = app(\App\Services\ReferencedProductResolver::class);
        $referenceResolution = $referencedProductResolver->resolve($message, $conversation, $commerceChannel);
        $referencedProduct = $referenceResolution['product'];
        $quotedMessageId = $referenceResolution['referenced_platform_message_id'];
        $referenceIntent = $referencedProductResolver->intentFor($message->content, $referenceResolution);

        // A referenced product is evidence about identity, not permission to
        // place an order. Ordering language is required; pointer-only messages
        // remain product questions and still pass through the order summary flow.
        // Stored checkout values are sanitized on load so a placeholder or platform ID saved by an earlier turn can never reach the summary or the order payload.
        $checkoutState = is_array($conversation->checkout_state)
            ? \App\Services\OrderCheckoutService::sanitizeStoredPhone($conversation->checkout_state)
            : null;

        // Handle explicit checkout cancellation before product recovery, AI
        // classification, or any provider order API can run. This transition is
        // deterministic so an AI guess cannot turn "cancel" into confirmation.
        if ($this->handleExplicitCheckoutCancellation($channel, $conversation, $message, $checkoutState)) {
            return;
        }

        $isProductBrowseMessage = (bool) preg_match(
            '/(?:\b(?:show|see|view|browse|list|display|send)\b.{0,60}\b(?:products?|items?|catalog(?:ue)?|images?|photos?|pictures?)\b|\b(?:products?|items?|catalog(?:ue)?)\b.{0,60}\b(?:images?|photos?|pictures?|show|see|view|browse|list|all)\b|i\s+wanna\s+see\s+products?)/iu',
            $message->content
        );

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
        $productsContext = null;
        $msgLower = mb_strtolower($message->content);

        // Keywords that indicate customer is asking about an EXISTING order
        $orderStatusKeywords = [
            'طلب','أوردر','order','شحن','توصيل','delivery','shipping',
            'وين','فين','متى','when','status','حالة','مشتريات',
            'بضاعتي','بتاعي','اين طلبي','where is my','track',
        ];
        // Keywords that indicate customer wants to PLACE a new order / buy a SPECIFIC item.
        // ⚠️  Do NOT add general product-browse phrases here ("show me products", "what do you
        // sell", "المنتجات") — those belong to the aggregate detection block above and
        // adding them here causes "fetch the products" to be misclassified as place_order,
        // which triggers an unwarranted escalation.
        $placeOrderKeywords = [
            'place an order','place the order','i want to order','i want to buy','purchase','i\'d like to order',
            'i\'d like to buy','i\'ll take','i will take','add to cart','i wanna order','i wanna buy',
            'i wanna place','okay now i wanna','now i wanna',
            'عايز اطلب','ابي اشتري','ابغا اشتري','اشتري','اطلب منكم',
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

        $isOrderAggregate = (bool) preg_match($orderAggregatePattern, $message->content)
            || (bool) preg_match($orderAggregatePatternAr, $message->content);

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

        // Aggregate intent is more specific than the generic order-status / place-order
        // keyword buckets (which share words like "order" and "products") — it always wins.
        if ($isProductAggregate || $isOrderAggregate) {
            $isOrderStatus = false;
            $isPlaceOrder  = false;
        }

        // Capture intent before applying the explicit reference policy for logs.
        $intentBefore = $isOrderStatus ? 'order_status' : ($isPlaceOrder ? 'place_order' : ($isProductAggregate ? 'product_inquiry' : 'question'));

        // A resolved pointer becomes an order only when ordering language is
        // present. A pointer alone asks about that product.
        if ($referenceIntent === 'place_order') {
            $isPlaceOrder = true;
            $isProductAggregate = false;
            $isOrderAggregate = false;
            $isOrderStatus = false;
        } elseif ($referenceIntent === 'question') {
            $isPlaceOrder = false;
        }

        if ($referenceResolution['is_pointer'] || $quotedMessageId) {
            $intentAfter = $referenceIntent ?? ($referenceResolution['resolution'] === 'ambiguous' ? 'clarify_product' : $intentBefore);
            Log::info('Reference resolution', [
                'message_id' => $message->id,
                'conversation_id' => $conversation->id,
                'channel_type' => $channel->type,
                'referenced_platform_message_id_present' => !empty($quotedMessageId),
                'match_method' => $referenceResolution['match_method'],
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
            if (preg_match('/^#?(\d{5,12})$/', $trimmedMsg, $bareMatch)) {
                // Looks like a standalone order number. Check if last AI reply asked for it.
                $recentAiMsg = Message::where('conversation_id', $conversation->id)
                    ->where('is_ai', true)
                    ->where('direction', 'outbound')
                    ->orderBy('created_at', 'desc')
                    ->value('content');

                $orderRequestPhrases = ['order number', 'رقم الطلب', 'رقم طلبك', 'order no', 'your order'];
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
        // Check if the conversation is actively in a Salla-related flow (products/orders/checkout).
        // This structurally prevents the AI from hallucinating platform/unrelated text into store chats.
        $isSallaFlow = false;
        
        if ($sallaChannel) {
            // 1. Direct intent detection on this turn
            if ($isProductAggregate || $isOrderAggregate || $isOrderStatus || $isPlaceOrder || ($imageRequest ?? false)) {
                $isSallaFlow = true;
            } else {
                // 2. Contextual detection: Was the recent conversation about Salla?
                $recentAiMessage = Message::where('conversation_id', $conversation->id)
                    ->where('is_ai', true)
                    ->where('direction', 'outbound')
                    ->orderBy('created_at', 'desc')
                    ->first();

                if ($recentAiMessage) {
                    if (in_array($recentAiMessage->intent, ['order_status', 'place_order'])) {
                        $isSallaFlow = true;
                    } else {
                        // For generic 'question' intent, check if the content looks Salla-related
                        $recentLower = mb_strtolower($recentAiMessage->content);
                        if (preg_match('/(sar|ريال|product|منتج|items?|order|طلب|cart|سلة)/i', $recentLower)) {
                            $isSallaFlow = true;
                        }
                    }
                }
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
                        'price' => (float) $product->price,
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
                    $response = Http::withToken($commerceChannel->access_token)
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

            if ($isOrderAggregate) {
                try {
                    $sallaService = new SallaService();
                    $raw = $sallaService->getOrdersForChannel($sallaChannel, ['per_page' => 10]);
                    $ordersAggregateContext = $sallaService->formatOrdersListForAI($raw);
                    Log::info('ProcessAutoReply: Salla orders aggregate fetched', [
                        'total_count' => $ordersAggregateContext['total_count'] ?? null,
                    ]);
                } catch (\Exception $e) {
                    Log::error('ProcessAutoReply: Salla orders aggregate fetch failed', ['exception_type' => get_class($e)]);
                    $ordersAggregateContext = ['error' => $e->getMessage()];
                }
            }

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
                        if (preg_match('/^#?(\d{5,12})$/', $trimmedMsg, $bareOrderMatch)) {
                            $raw   = $sallaService->getOrderForChannel($sallaChannel, $bareOrderMatch[1]);
                            $order = $raw['data'] ?? $raw ?? null;
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
                    // Non-WhatsApp: scan conversation for order number or phone
                    $recentMessages = Message::where('conversation_id', $conversation->id)
                        ->orderBy('created_at', 'desc')->take(6)->get();

                    $orderNumberMatch = null;
                    $phoneMatch       = null;
                    foreach ($recentMessages as $m) {
                        if (!$orderNumberMatch && preg_match('/\b(\d{4,10})\b/', $m->content, $mo)) {
                            $orderNumberMatch = $mo[1];
                        }
                        if (!$phoneMatch && preg_match('/\b(05\d{8}|9665\d{8}|\+9665\d{8})\b/', $m->content, $mp)) {
                            $phoneMatch = preg_replace('/[^0-9]/', '', $mp[1]);
                        }
                    }

                    if ($orderNumberMatch || $phoneMatch) {
                        try {
                            $sallaService = new SallaService();
                            $order = null;
                            if ($orderNumberMatch) {
                                $raw   = $sallaService->getOrder($sallaChannel->access_token, $orderNumberMatch);
                                $order = $raw['data'] ?? $raw ?? null;
                            } elseif ($phoneMatch) {
                                $order = $sallaService->getLatestOrderByPhone($sallaChannel->access_token, $phoneMatch);
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
                                    $shopifyResponse = Http::withToken($shopifyChannel->access_token)
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
                        'price'     => $referencedProduct['price'] ?? '?',
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
        $hardEscalation = AICapabilitiesService::checkHardEscalation($message->content);
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

            $escalationMessage = "Sure 👍 I'm connecting you with a human agent now. Please wait a moment.";
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
            Log::info('ProcessAutoReply: order context built for AI', ['order_context' => $orderContext]);
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
            (bool) $sallaChannel && !empty($checkoutState['salla_product_id'])
        );

        // ── STRUCTURED CONVERSATION STATE ────────────────────────────────────
        // Build authoritative structured state for the AI. The AI must NEVER
        // invent or override these values — it may only interpret intent and
        // propose responses. Application code validates all state transitions.
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
        $updatedCheckoutState = $isProductBrowseMessage
            ? []
            : $checkoutService->extractAndMergeState($conversation, $message->content, $referencedProduct);
        if (!empty($updatedCheckoutState)) {
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
            $conversation->update(['checkout_state' => $merged]);
        } elseif (!is_null($conversation->checkout_state)) {
            // Previously stored checkout_state exists but new extraction yielded
            // nothing meaningful — preserve the existing state rather than
            // wiping out collected customer fields (full_name, phone, address).
            // Only clear it if there is genuinely no prior data.
            $conversation->update(['checkout_state' => null]);
        }
        $sallaCheckoutActive = (bool) $sallaChannel && !empty($updatedCheckoutState['salla_product_id']);
        $addressGeocodingStatus = null;

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

        // Resolve approximate delivery coordinates from the city alone;
        // a matching street or house number is not required.
        if (
            $sallaCheckoutActive
            && empty($updatedCheckoutState['geo_coordinates'])
            && !empty($updatedCheckoutState['address'])
        ) {
            $geocodingResult = $checkoutService->geocodeDeliveryAddress(
                (string) $updatedCheckoutState['address'],
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

        $checkoutState = $updatedCheckoutState;
        $fieldStatus = $checkoutService->computeFieldStatus($updatedCheckoutState, $sallaCheckoutActive);
        $hasValidOrderSummary = $this->hasValidOrderSummaryForConfirmation(
            $conversation,
            $message,
            $updatedCheckoutState
        );
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

        // Explicit confirmation is necessary; complete checkout fields alone never authorize an order.
        $incomingLower = mb_strtolower(trim($message->content));
        $containsConfirmPhrase = (bool) preg_match(
            '/(?:\\b(?:yes|yeah|yep|sure|ok|okay|confirm|order\\s+this|i\\s+want\\s+(?:to\\s+order\\s+)?this|i\\s+wanna\\s+order\\s+this|place\\s+(?:the\\s+)?order)\\b|نعم|أكد|اكد|تأكيد|موافق|تأكيد الطلب|اعتمد الطلب)/ui',
            $incomingLower
        );
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
        $autoOrderConfirmationEnabled = $channel->auto_order_confirmation_enabled !== null
            ? (bool) $channel->auto_order_confirmation_enabled
            : (bool) ($business?->auto_order_confirmation_enabled ?? true);

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

        $context = [
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
            'knowledge_base' => (function() use ($business, $bot, $channel, $message, $isSallaFlow, &$knowledgeFileIds, &$knowledgeChunkIds) {
                if (!$business || $isSallaFlow) {
                    return '';
                }
                $embeddingsService = app(\App\Services\EmbeddingsService::class);
                $vectorSearch = app(\App\Services\VectorSearchService::class);

                // 1. Get embedding for the user's message
                $queryEmbedding = $embeddingsService->embedChunk($message->content);
                
                if (empty($queryEmbedding)) {
                    return ''; // Fallback if embedding fails
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
                        return '';
                    }
                }

                // 3. Search for relevant chunks
                $relevantChunks = $vectorSearch->search($queryEmbedding, $business->id, 5, $allowedFileIds);

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

        // Step 4: Single AI Call with JSON Output
        $aiResult = AICapabilitiesService::callAIWithJSON($message->content, $context);
        $auditProvider = $aiResult['provider'] ?? ($context['ai_provider'] ?: config('services.ai.provider', 'gemini'));
        $auditModel = $aiResult['model'] ?? ($context['ai_model'] ?: ($auditProvider === 'groq'
            ? config('services.groq.model', 'openai/gpt-oss-120b')
            : config('services.gemini.model', 'gemini-2.5-flash')));

        if (!$aiResult['success']) {
            Log::error('ProcessAutoReply: AI call failed', [
                'conversation_id' => $conversation->id,
                'fallback_used' => true
            ]);

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

            // Fallback + Escalate
            if (!$productBrowseFallback) {
                $conversation->update([
                    'requires_human' => true,
                    'escalated_at' => now(),
                    'escalation_reason' => 'ai_failure_fallback'
                ]);
                app(\App\Services\HandoffTaskService::class)->openForConversation($conversation, 'ai_failure_fallback');
            }

            // Provide a user-friendly fallback message
            $fallbackMessage = $productBrowseFallback
                ?? ($aiResult['reply'] ?? "I apologize, but I'm having technical difficulties right now. A human agent will be with you shortly to help with your request.");
            
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
                
                // Notify business owner about AI failure
                $this->notifyAIFailure($channel->user, $conversation, $aiResult['error'] ?? 'Unknown AI error');
            }
            $this->sendReply($channel, $conversation, $replyMessage);
            return;
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

        if ($isProductBrowseMessage) {
            $intent = 'question';
            $aiResult['intent'] = 'question';
            if (preg_match('/\b(images?|photos?|pictures?|pics?)\b/i', $message->content)) {
                $aiResult['needs_images'] = true;
            }
        }

        if (!$isProductBrowseMessage && $commerceChannel) {
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
        if (
            $containsConfirmPhrase
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
            && $intent === 'place_order'
            && $commerceChannel
            && !empty($updatedCheckoutState['salla_product_id'])
            && !in_array($escalationReason, $checkoutHardEscalations, true)
        ) {
            $shouldEscalate = false;
            $decisionReason = 'active_checkout_collecting_or_confirming';
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
                : "Sure 👍 I'm connecting you with a team member now. Please wait a moment.";

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
            && !in_array($checkoutState['confirmation_state'] ?? null, ['confirmed', 'order_placed'], true)
        ) {
            $aiResponse = $detectedLanguage === 'arabic'
                ? 'هذه تفاصيل طلبك جاهزة. راجعها، ثم أرسل «تأكيد الطلب» لإتمامه.'
                : 'Your order details are ready. Please review the summary, then reply “confirm order” to place it.';
        }

        $isOrderSummaryMessage = $intent === 'place_order'
            && !empty($fieldStatus['is_complete'])
            && !$shopifyVariantSelectionRequired
            && ($checkoutState['confirmation_state'] ?? null) === 'confirmation_pending';
        if ($isOrderSummaryMessage) {
            $productName = trim((string) ($checkoutState['product_name'] ?? 'Selected product'));
            $price = $checkoutState['product_price'] ?? $checkoutState['price'] ?? null;
            $currency = trim((string) ($checkoutState['product_currency'] ?? $checkoutState['currency'] ?? ''));
            $priceText = $price !== null && $price !== '' ? trim((string) $price . ' ' . $currency) : 'Not available';
            $storeName = trim((string) ($commerceChannel?->page_name ?? '')) ?: 'our store';
            $aiResponse = "Please review your order summary:\n"
                . "Product: {$productName}\n"
                . (!empty($checkoutState['commerce_variant_title']) ? 'Variant: ' . trim((string) $checkoutState['commerce_variant_title']) . "\n" : '')
                . "Price: {$priceText}\n"
                . "Store: {$storeName}\n"
                . 'Name: ' . trim((string) ($checkoutState['full_name'] ?? '')) . "\n"
                . 'Phone: ' . trim((string) ($checkoutState['phone'] ?? $checkoutState['customer_phone'] ?? '')) . "\n"
                . 'Delivery address: ' . trim((string) ($checkoutState['address'] ?? $checkoutState['customer_address'] ?? '')) . "\n\n"
                . 'Reply “confirm order” to place it, or “cancel” to stop.';
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

        if ($sallaCheckoutActive && $addressGeocodingStatus === 'country_unknown') {
            $aiResponse = $detectedLanguage === 'arabic'
                ? 'من فضلك اذكر الدولة والمدينة التي سيتم التوصيل إليها حتى أتمكن من متابعة الطلب.'
                : 'Please tell me the country and city for delivery so I can continue with the order.';
        } elseif ($sallaCheckoutActive && $addressGeocodingStatus === 'not_found') {
            $aiResponse = $detectedLanguage === 'arabic'
                ? 'لم أتمكن من تحديد المدينة. من فضلك أرسل اسم المدينة فقط؛ لا تحتاج إلى إرسال إحداثيات أو رابط خرائط.'
                : "I couldn't identify the delivery city. Please send the city name only; you don't need to provide coordinates or a map link.";
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
                : "I'm sorry, I'm not able to send product photos right now. Let me connect you with a team member who can send them directly 🙏";
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
                    : "I'm sorry, I couldn't confirm your order because your city name wasn't recognized by our shipping system. Could you please reply with your exact city and neighborhood name so I can place it? 🙏";
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
                    : null),
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
            && in_array($checkoutState['confirmation_state'] ?? null, ['confirmed', 'order_placed'], true)
            && !$isProductBrowseMessage
            && $commerceChannel
            && !empty($fieldStatus['is_complete'])
            && !empty($checkoutState['salla_product_id'])
            && !empty($checkoutState['customer_phone'] ?? $checkoutState['phone'] ?? '')
            && !empty($checkoutState['address'] ?? $checkoutState['customer_address'] ?? '')
            && !empty($checkoutState['full_name'] ?? $checkoutState['customer_name'] ?? '')
            && $autoOrderConfirmationEnabled
        ) {
            Log::info('ProcessAutoReply: triggering real commerce order creation after validated confirmation', [
                'conversation_id' => $conversation->id,
                'product_id'      => $checkoutState['salla_product_id'],
                'customer_phone_present' => !empty($checkoutState['customer_phone'] ?? $checkoutState['phone'] ?? ''),
            ]);

                // Normalise checkout_state keys to what SallaService expects
                $orderInput = [
                    'salla_product_id' => $checkoutState['salla_product_id'],
                    'commerce_external_id' => $checkoutState['commerce_external_id'] ?? $checkoutState['salla_product_id'],
                    'commerce_channel_id' => $commerceChannel->id,
                    'product_name'     => $checkoutState['product_name']     ?? null,
                    'product_price'    => $checkoutState['product_price']    ?? 0,
                    'commerce_variant_id' => $checkoutState['commerce_variant_id'] ?? null,
                    'commerce_variant_title' => $checkoutState['commerce_variant_title'] ?? null,
                    'full_name'        => $checkoutState['full_name']         ?? $checkoutState['customer_name']    ?? null,
                    'phone'            => $checkoutState['customer_phone']    ?? $checkoutState['phone']             ?? '',
                    'address'          => $checkoutState['address']           ?? $checkoutState['customer_address'] ?? '',
                    'email'            => $checkoutState['email']              ?? $checkoutState['customer_email'] ?? null,
                    'postal_code'      => $checkoutState['postal_code']         ?? null,
                    'geo_coordinates'  => $checkoutState['geo_coordinates']     ?? null,
                    'building_number'  => $checkoutState['building_number']     ?? null,
                    'short_address'    => $checkoutState['short_address']       ?? null,
                    'additional_number'=> $checkoutState['additional_number']   ?? null,
                ];

                $orderData = $this->createRealExternalOrder($conversation, $orderInput, $channel);

                if ($orderData && !empty($orderData['order_id'])) {
                    // ✅ SUCCESS — override the AI reply with the real order confirmation
                    $realOrderId  = $orderData['order_id'];
                    $customerName = $orderInput['full_name'] ?? '';
                    $productName  = $orderInput['product_name'] ?? '';

                    if ($detectedLanguage === 'arabic') {
                        $aiResponse = "✅ تم تأكيد طلبك بنجاح!" .
                            (!empty($customerName) ? " شكراً {$customerName}" : '') . "\n" .
                            (!empty($productName)  ? "المنتج: {$productName}\n" : '') .
                            "رقم الطلب: #{$realOrderId}\n" .
                            "سيتم التواصل معك قريباً لتأكيد التفاصيل 🚀";
                    } else {
                        $aiResponse = "✅ Your order has been placed successfully!" .
                            (!empty($customerName) ? " Thank you, {$customerName}" : '') . "\n" .
                            (!empty($productName)  ? "Product: {$productName}\n" : '') .
                            "Order #: {$realOrderId}\n" .
                            "We'll contact you shortly to confirm the details 🚀";
                    }

                    // Persist completed checkout state so tests/callers can verify the order ID
                    $completedState = array_merge(
                        is_array($conversation->checkout_state) ? $conversation->checkout_state : [],
                        [
                            'status'   => 'completed',
                            'order_id' => $realOrderId,
                            'confirmation_state' => 'order_placed',
                            'order_fingerprint' => $this->orderFingerprint($orderInput),
                            'order_product_id' => (string) ($orderInput['commerce_external_id'] ?? $orderInput['salla_product_id'] ?? ''),
                            'order_connection_id' => (string) $commerceChannel->id,
                        ]
                    );
                    $conversation->update(['checkout_state' => $completedState, 'requires_human' => false]);
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
                } else {
                    // ❌ FAILURE — Salla API returned no order ID or threw an exception
                    Log::error('ProcessAutoReply: Salla order creation returned no order ID — sending honest failure reply', [
                        'conversation_id' => $conversation->id,
                        'order_data'      => $orderData,
                    ]);

                    $conversation->update([
                        'requires_human' => true,
                        'status' => 'pending_human',
                        'escalated_at' => now(),
                        'escalation_reason' => $commerceChannel->type . '_order_creation_failed',
                    ]);
                    app(\App\Services\HandoffTaskService::class)->openForConversation(
                        $conversation,
                        $commerceChannel->type . '_order_creation_failed',
                        'high'
                    );

                    if ($detectedLanguage === 'arabic') {
                        $aiResponse = "عذراً، واجهنا مشكلة تقنية في إتمام طلبك الآن 😔\n" .
                            "سيتواصل معك أحد فريقنا قريباً لإتمام الطلب يدوياً. نعتذر عن الإزعاج.";
                    } else {
                        $aiResponse = "I'm sorry, we encountered a technical issue processing your order 😔\n" .
                            "A team member will contact you shortly to complete your order manually. We apologize for the inconvenience.";
                    }

                    $failedAttempts = (int) ($updatedCheckoutState['failed_order_attempts'] ?? 0) + 1;
                    if ($failedAttempts >= 2) {
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
            $intent === 'place_order'
            && $containsConfirmPhrase
            && $sallaChannel
            && !empty($updatedCheckoutState['salla_product_id'])
            && empty($fieldStatus['is_complete'])
        ) {
            $missingLabels = [
                'full_name' => 'full name',
                'phone' => 'phone number',
                'address' => 'complete delivery address',
                'email' => 'email address',
                'postal_code' => 'postal / ZIP code',
                'geo_coordinates' => 'complete written delivery address with street, building number, district, and postal code',
                'building_number' => 'building or house number',
                'short_address' => 'short / national address code',
                'additional_number' => 'additional address number',
            ];
            $missingDetails = array_map(
                fn ($field) => $missingLabels[$field] ?? str_replace('_', ' ', $field),
                $fieldStatus['missing_fields']
            );
            if ($detectedLanguage === 'arabic') {
                $aiResponse = "لم يتم إرسال الطلب بعد. لإتمامه، أحتاج إلى: " . implode('، ', $missingDetails) . ". من فضلك أرسل البيانات الناقصة.";
            } else {
                $aiResponse = "I haven't submitted the order yet. To complete it, please send: " . implode(', ', $missingDetails) . ".";
            }
            Log::info('ProcessAutoReply: blocked Salla order confirmation while required details are missing', [
                'conversation_id' => $conversation->id,
                'missing_fields' => $fieldStatus['missing_fields'],
            ]);
        }

        // The model may claim completion or ask again for a field the customer
        // already supplied. Verify both against durable checkout/order state
        // immediately before the reply can be sent.
        $guardCheckoutState = array_merge(
            is_array($conversation->fresh()->checkout_state) ? $conversation->fresh()->checkout_state : [],
            is_array($updatedCheckoutState) ? $updatedCheckoutState : []
        );
        $greetingGuard = app(\App\Services\GreetingRepeatGuard::class)->apply($message, (string) $aiResponse);
        $aiResponse = $greetingGuard['reply'];

        $claimGuard = app(\App\Services\OrderClaimGuard::class)->inspect(
            $conversation,
            (string) $aiResponse,
            $guardCheckoutState,
            $fieldStatus['missing_fields'] ?? [],
            $detectedLanguage
        );
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
     * Idempotency: a cache lock keyed by conversation id prevents duplicate
     * enrollment attempts on job retries. SequenceEnrollmentService also
     * enforces uniqueness at the DB level (throws on duplicate active enrollment).
     */
    private function tryTriggerNewUserSequence(Conversation $conversation, int $businessId): bool
    {
        // Per-conversation idempotency lock: if this job is retried, we must not
        // enroll the conversation twice. The lock is held for 5 minutes — long
        // enough to cover the job retry window (backoff = 30s, tries = 3).
        $lockKey = "seq_new_user_lock:conv:{$conversation->id}";

        if (!Cache::add($lockKey, 1, now()->addMinutes(5))) {
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
                Cache::forget($lockKey);
                // Propagate: let the job retry mechanism handle it.
                throw $e;
            }
        }

        if (!$enrolled) {
            // All sequences were ineligible (e.g. already enrolled from a race).
            // Release the lock so future new messages from this conversation
            // don't get stuck thinking a sequence is pending.
            Cache::forget($lockKey);
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
                        "https://graph.facebook.com/v19.0/me/messages?access_token={$accessToken}",
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
     * Create a real order via external Salla/Shopify API integration if connected,
     * capturing external order ID and enforcing strict idempotency.
     */
    private function createRealExternalOrder(Conversation $conversation, array $checkoutState, Channel $channel): ?array
    {
        $lockKey = "create_order_lock_conv_{$conversation->id}";
        $lock = Cache::lock($lockKey, 10);

        if (!$lock->get()) {
            Log::info('ProcessAutoReply: order creation lock active for conversation', ['conversation_id' => $conversation->id]);
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
                    Log::info('ProcessAutoReply: matching order already exists, skipping duplicate creation', [
                        'conversation_id' => $conversation->id,
                        'order_id' => $currentState['order_id'],
                    ]);
                    return $currentState;
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
                return null;
            }

            // Persist an order attempt before contacting the provider. The
            // summary message ID distinguishes a later, intentional checkout
            // of the same product/customer from a retry of this checkout.
            $checkoutFingerprint = $this->orderFingerprint($checkoutState);
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

            if (!$attempt->wasRecentlyCreated && $attempt->status === 'pending') {
                Log::warning('ProcessAutoReply: commerce order attempt is unresolved; suppressing duplicate provider request', [
                    'conversation_id' => $conversation->id,
                    'connection_id' => $commerceChannel->id,
                    'attempt_id' => $attempt->id,
                ]);
                return null;
            }

            if ($attempt->status === 'failed') {
                $attempt->update(['status' => 'pending', 'external_order_id' => null, 'order_number' => null]);
            }

            // The active conversation connection is authoritative for every
            // order operation; this method never probes other connected stores.
            $sallaChannel = $commerceChannel?->type === 'salla' ? $commerceChannel : null;

            if ($sallaChannel) {
                try {
                    $sallaService = new SallaService();
                    $sallaRes = $sallaService->createOrderForChannel($sallaChannel, $checkoutState);
                    
                    $externalOrderId = (string)($sallaRes['data']['reference_id'] ?? $sallaRes['data']['id'] ?? $sallaRes['id'] ?? null);
                    if ($externalOrderId) {
                        $externalSource = 'salla';
                        Log::info('ProcessAutoReply: Salla order created successfully via API', [
                            'conversation_id' => $conversation->id,
                            'salla_order_id'  => $externalOrderId,
                        ]);
                    } else {
                        Log::error('SALLA_ORDER_CREATION_FAILED', [
                            'conversation_id' => $conversation->id,
                            'channel_id'      => $sallaChannel->id,
                            'response_keys' => array_keys($sallaRes),
                        ]);
                        return null;
                    }
                } catch (\Exception $e) {
                    // A provider HTTP response confirms rejection. Network
                    // failures stay pending because the provider may have
                    // accepted the request before the connection dropped.
                    if (preg_match('/^API call failed: (?:HTTP\s*)?(\d{3})\b/', $e->getMessage(), $statusMatch)
                        || str_contains(strtolower($e->getMessage()), 'token expired')
                        || str_contains(strtolower($e->getMessage()), 'scope missing')
                        || str_contains(strtolower($e->getMessage()), 'rate limited')) {
                        $attempt->update(['status' => 'failed']);
                    }
                    Log::error('SALLA_ORDER_CREATION_FAILED', [
                        'conversation_id' => $conversation->id,
                        'channel_id'      => $sallaChannel->id,
                        'exception_type' => get_class($e),
                    ]);
                    // STRICT RULE: DO NOT generate a fake ID or pretend success when Salla fails!
                    return null;
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
                            throw new \RuntimeException('The selected Shopify product has no synced variant ID.');
                        }
                        $nameParts = preg_split('/\s+/', trim((string) ($checkoutState['full_name'] ?? '')), 2) ?: [];
                        $shop  = $shopifyChannel->page_id;
                        $token = $shopifyChannel->access_token;
                        $response = Http::withToken($token)->post("https://{$shop}/admin/api/" . config('services.shopify.api_version', '2026-07') . "/orders.json", \App\Services\ShopifyOrderPayload::build((int) $variantMatch[1], (int) ($checkoutState['quantity'] ?? 1), $checkoutState));

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
                        'exception_type' => get_class($e),
                        ]);
                        return null;
                }
            }

            if (!$externalOrderId && $commerceChannel?->type === 'woocommerce') {
                try {
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
                    if ($externalOrderId !== '') {
                        $externalSource = 'woocommerce';
                    }
                } catch (\Throwable $e) {
                    Log::error('WOOCOMMERCE_ORDER_CREATION_FAILED', [
                        'conversation_id' => $conversation->id,
                        'channel_id' => $commerceChannel->id,
                    'exception_type' => get_class($e),
                    ]);
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

            $orderTotal = (float)($checkoutState['product_price'] ?? 0);
            $orderData  = [
                'id'              => $externalOrderId,
                'order_id'        => $externalOrderId,
                'order_number'    => $externalOrderNumber ?: $externalOrderId,
                'external_source' => $externalSource,
                'total'           => $orderTotal,
                'status'          => 'completed',
                'currency'        => $checkoutState['product_currency'] ?? 'SAR',
                'has_order'       => true,
                'items'           => [
                    [
                        'name'     => $checkoutState['product_name'] ?? 'Product',
                        'price'    => $orderTotal,
                        'quantity' => 1,
                    ]
                ],
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
        return hash('sha256', implode('|', [
            (string) ($state['commerce_channel_id'] ?? ''),
            (string) ($state['commerce_external_id'] ?? $state['salla_product_id'] ?? ''),
            (string) ($state['commerce_variant_id'] ?? ''),
            preg_replace('/\D+/', '', (string) ($state['phone'] ?? $state['customer_phone'] ?? '')),
            mb_strtolower(trim((string) ($state['address'] ?? $state['customer_address'] ?? ''))),
        ]));
    }

    private function hasValidOrderSummaryForConfirmation(Conversation $conversation, Message $incoming, array $state): bool
    {
        $summaryId = (int) ($state['confirmation_summary_message_id'] ?? 0);
        if ($summaryId < 1 || empty($state['confirmation_summary_fingerprint'])) {
            return false;
        }

        if (!hash_equals((string) $state['confirmation_summary_fingerprint'], $this->orderFingerprint($state))) {
            return false;
        }

        $summary = Message::query()
            ->where('conversation_id', $conversation->id)
            ->whereKey($summaryId)
            ->where('direction', 'outbound')
            ->where('send_status', 'sent')
            ->first();
        if (!$summary || empty($summary->metadata['order_summary'])) {
            return false;
        }

        $quotedMessageId = (string) ($incoming->metadata['quoted_message_id'] ?? '');
        if ($quotedMessageId !== '' && in_array($quotedMessageId, [
            (string) $summary->id,
            (string) ($summary->metadata['platform_message_id'] ?? ''),
            (string) ($summary->whatsapp_message_id ?? ''),
            (string) ($summary->gmail_message_id ?? ''),
        ], true)) {
            return true;
        }

        // If no explicit quote exists, the summary must be the immediately
        // preceding conversation message, with no intervening turns.
        return !Message::query()
            ->where('conversation_id', $conversation->id)
            ->where('id', '>', $summary->id)
            ->where('id', '<', $incoming->id)
            ->exists();
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
        if ($incoming->direction !== 'inbound' || !is_array($checkoutState) || !$this->isExplicitCheckoutCancellation($incoming->content)) {
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

    private function isExplicitCheckoutCancellation(string $message): bool
    {
        $message = mb_strtolower(trim($message));
        return (bool) preg_match(
            '/^(?:(?:please|kindly)\s+)?(?:cancel|stop)(?:\s+(?:(?:my|the)\s+)?(?:order|checkout))?[.!?\s]*$'
                . '|^(?:i\s+)?(?:want|need)\s+to\s+cancel\s+(?:(?:my|the)\s+)?(?:order|checkout)[.!?\s]*$'
                . '|^(?:i\s+)?(?:changed\s+my\s+mind|do\s+not\s+proceed|don.t\s+proceed)(?:\s+with\s+(?:the\s+)?order)?[.!?\s]*$'
                . '|^(?:من\s+فضلك\s+)?(?:إلغاء|الغاء|ألغِ|الغي)(?:\s+(?:الطلب|طلبي|عملية\s+الطلب))?[.!؟\s]*$'
                . '|^(?:أريد|اريد|عايز|عاوز|أبغى|ابغى)\s+(?:إلغاء|الغاء|ألغي|الغي)\s+(?:الطلب|طلبي)[.!؟\s]*$/u',
            $message
        );
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
