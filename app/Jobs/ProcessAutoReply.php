<?php

namespace App\Jobs;

use App\Models\Channel;
use App\Models\Conversation;
use App\Models\Message;
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

        // Check subscription limits using cached counter
        $user = $channel->user;
        if (!$user) {
            Log::warning('ProcessAutoReply: channel has no user', ['channel_id' => $channel->id]);
            return;
        }

        $subscription = $user->activeSubscription;
        $package = $subscription ? $subscription->package : \App\Models\Package::where('name', 'Free')->first();

        if (!$package) {
            Log::error('ProcessAutoReply: no package found', ['user_id' => $user->id]);
            return;
        }

        // Use atomic counter for AI replies limit check to prevent race conditions
        $cacheKey = "user_{$user->id}_ai_replies_" . now()->format('Y-m');
        $lockKey = "lock:{$cacheKey}";
        
        // Try to acquire a lock for atomic operation
        $lock = Cache::lock($lockKey, 10);
        
        try {
            if ($lock->get()) {
                // Initialize counter if not exists
                if (!Cache::has($cacheKey)) {
                    $count = Message::where('is_ai', true)
                        ->where('created_at', '>=', now()->startOfMonth())
                        ->whereHas('conversation.channel', function ($query) use ($user) {
                            $query->where('user_id', $user->id);
                        })
                        ->count();
                    Cache::put($cacheKey, $count, now()->endOfMonth());
                }
                
                $aiRepliesThisMonth = (int) Cache::get($cacheKey);
                
                // Check limit BEFORE incrementing — increment only after message is actually sent
                if ($package->ai_replies_limit !== -1 && $aiRepliesThisMonth >= $package->ai_replies_limit) {
                    Log::info('ProcessAutoReply: AI replies limit reached', [
                        'user_id' => $user->id,
                        'limit' => $package->ai_replies_limit,
                        'used' => $aiRepliesThisMonth
                    ]);
                    return;
                }
                
                // Increment counter atomically
                Cache::increment($cacheKey);
                
                // Store the incremented value for later use
                $this->aiRepliesCount = $aiRepliesThisMonth + 1;
            } else {
                // Failed to acquire lock, fall back to database check
                Log::warning('ProcessAutoReply: Failed to acquire lock, using database fallback', [
                    'user_id' => $user->id
                ]);
                
                $aiRepliesThisMonth = Message::where('is_ai', true)
                    ->where('created_at', '>=', now()->startOfMonth())
                    ->whereHas('conversation.channel', function ($query) use ($user) {
                        $query->where('user_id', $user->id);
                    })
                    ->count();
                
                if ($package->ai_replies_limit !== -1 && $aiRepliesThisMonth >= $package->ai_replies_limit) {
                    Log::info('ProcessAutoReply: AI replies limit reached (fallback)', [
                        'user_id' => $user->id,
                        'limit' => $package->ai_replies_limit,
                        'used' => $aiRepliesThisMonth
                    ]);
                    return;
                }
                
                $this->aiRepliesCount = $aiRepliesThisMonth + 1;
            }
        } finally {
            $lock?->release();
        }

        $detectedLanguage = $this->detectLanguage($message->content);

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
            $replyMessage = Message::create([
                'conversation_id' => $message->conversation_id,
                'content' => $afterHoursMessage,
                'direction' => 'outbound',
                'status' => 'auto',
                'is_ai' => true,
                'source' => 'ai_hours_routing',
                'send_status' => 'pending',
            ]);

            $conversation->update([
                'requires_human' => true,
                'escalated_at' => now(),
                'escalation_reason' => 'after_hours'
            ]);

            $this->sendReply($channel, $conversation, $replyMessage);
            return;
        }

        // Apply tone/persona customization
        $toneStyle = $channel->business?->ai_tone_style ?? [
            'tone' => 'friendly',
            'formality' => 'casual',
            'focus' => 'support'
        ];
        
        // ── CRITICAL ISSUE — REPLY-TO-PRODUCT CONTEXT / PRODUCT SELECTION ──────
        // If this incoming message is a WhatsApp reply to a specific product
        // image we previously sent, resolve the EXACT product deterministically
        // from the persisted message→product mapping. This must happen BEFORE
        // any keyword/AI intent classification and must NEVER be left to AI
        // inference — see ProductMessageMap / EvolutionApiService::extractQuotedMessageId().
        //
        // Priority order for product identification (per spec):
        //   1. Replied-to product message (this block)
        //   2. Active checkout_state on the conversation (persisted across turns)
        //   3. Explicit product ID/SKU supplied by user (left to existing AI/product context)
        //   4. AI inference (last resort only)
        $referencedProduct = null;
        $quotedMessageId = $message->metadata['quoted_message_id'] ?? null;

        if ($quotedMessageId) {
            $productMap = \App\Models\ProductMessageMap::where('conversation_id', $message->conversation_id)
                ->where('whatsapp_message_id', $quotedMessageId)
                ->latest('id')
                ->first();

            if ($productMap) {
                $referencedProduct = [
                    'salla_product_id' => $productMap->salla_product_id,
                    'sku'              => $productMap->sku,
                    'name'             => $productMap->product_name,
                    'price'            => $productMap->product_price,
                    'currency'         => $productMap->currency ?? 'SAR',
                    'image'            => $productMap->image_url,
                ];

                Log::info('ProcessAutoReply: resolved referenced product from replied-to message', [
                    'conversation_id'   => $message->conversation_id,
                    'quoted_message_id' => $quotedMessageId,
                    'product_id'        => $referencedProduct['salla_product_id'],
                ]);
            } else {
                Log::info('ProcessAutoReply: message replies to a quoted message but no product mapping was found for it', [
                    'conversation_id'   => $message->conversation_id,
                    'quoted_message_id' => $quotedMessageId,
                ]);
            }
        }

        // ── CHECKOUT STATE RECOVERY ─────────────────────────────────────────
        // If the customer is mid-way through an order collection flow (they have
        // already identified a product and the bot is collecting name/phone/address),
        // their follow-up messages (providing those details) will NOT have a
        // quoted_message_id — so referencedProduct would be null above, causing
        // the place_order branch to re-fetch products, time out on an expired
        // token, and show a "product catalogue not available" apology.
        //
        // Fix: persist a checkout_state JSON on the conversation every time a
        // product is confirmed during place_order, then load it back here so the
        // product identity is never lost between turns.
        $checkoutState = $conversation->checkout_state ?? null;

        if (!$referencedProduct && !empty($checkoutState['salla_product_id'])) {
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

        // Add product awareness to AI context
        if ($business) {
            $productService = new ProductAwarenessService();
            $productsContext = $productService->buildProductContextArray($business->id);
        }

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

        $isProductAggregate = (bool) preg_match($productAggregatePattern, $message->content)
            || (bool) preg_match($productAggregatePatternAr, $message->content);

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

        // ── REPLY-TO-PRODUCT "THIS ONE" INTENT ──────────────────────────────
        // When a specific product was deterministically resolved from a
        // replied-to image (see block above), phrases like "I want this one",
        // "order this", "give me this one" unambiguously mean BUY that exact
        // product — never a generic browse/aggregate request, and never
        // something the AI should re-classify by guessing. Only fires when a
        // product was actually resolved, so it can't misfire on ordinary text.
        if ($referencedProduct) {
            $thisProductOrderPattern = '/\b(i want this( one)?|i wanna (order|buy) this|order this( one)?|buy this( one)?|this one|give me this( one)?|i\'?ll take this|i will take this|add this to (my )?(order|cart))\b/iu';
            if (preg_match($thisProductOrderPattern, $msgLower)) {
                $isPlaceOrder      = true;
                $isProductAggregate = false;
                $isOrderAggregate   = false;
                $isOrderStatus      = false;
                Log::info('ProcessAutoReply: "this one" reply-to-product phrasing detected — forcing place_order intent', [
                    'conversation_id' => $conversation->id,
                    'product_id'      => $referencedProduct['salla_product_id'],
                ]);
            }
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

        // Find Salla channel for this user
        $sallaChannel = Channel::where('user_id', $user->id)
            ->where('type', 'salla')
            ->where('status', 'connected')
            ->first();

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
                    Log::error('ProcessAutoReply: Salla products aggregate fetch failed', ['error' => $e->getMessage()]);
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
                    Log::error('ProcessAutoReply: Salla orders aggregate fetch failed', ['error' => $e->getMessage()]);
                    $ordersAggregateContext = ['error' => $e->getMessage()];
                }
            }

            // ── ORDER STATUS FLOW ────────────────────────────────────────────
            if ($isOrderStatus) {
                $rawPhone = preg_replace('/[^0-9]/', '', $conversation->sender_id ?? '');

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
                                    'phone'    => $rawPhone,
                                    'order_id' => $order['id'] ?? null,
                                ]);
                            }
                        }
                        // If no order found, sallaContext stays null → AI will ask for order number
                    } catch (\Exception $e) {
                        Log::warning('ProcessAutoReply: Salla order lookup failed', ['error' => $e->getMessage()]);
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
                            Log::warning('ProcessAutoReply: Salla order lookup (non-WA) failed', ['error' => $e->getMessage()]);
                        }

                        // Fallback to Shopify if Salla lookup failed
                        if (!$sallaContext) {
                            try {
                                $shopifyChannel = Channel::where('user_id', $channel->user_id)
                                    ->where('type', 'shopify')
                                    ->where('status', 'connected')
                                    ->first();
                                
                                if ($shopifyChannel && $phoneMatch) {
                                    $shopifyResponse = Http::withToken($shopifyChannel->access_token)
                                        ->get("https://{$shopifyChannel->page_id}/admin/api/2024-01/orders.json", [
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
                                Log::warning('ProcessAutoReply: Shopify order lookup failed', ['error' => $e->getMessage()]);
                            }
                        }

                        // Fallback to WooCommerce if Shopify lookup also failed
                        if (!$sallaContext) {
                            try {
                                $wooCommerceChannel = Channel::where('user_id', $channel->user_id)
                                    ->where('type', 'woocommerce')
                                    ->where('status', 'connected')
                                    ->first();
                                
                                if ($wooCommerceChannel && $phoneMatch) {
                                    $wooCommerceService = new \App\Services\WooCommerceService();
                                    $wooOrder = $wooCommerceService->getOrderByPhone($wooCommerceChannel->metadata, $phoneMatch);
                                    
                                    if ($wooOrder) {
                                        $sallaContext = $wooCommerceService->formatOrderForAI($wooOrder);
                                        Log::info('ProcessAutoReply: WooCommerce order lookup succeeded');
                                    }
                                }
                            } catch (\Exception $e) {
                                Log::warning('ProcessAutoReply: WooCommerce order lookup failed', ['error' => $e->getMessage()]);
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
                            Log::warning('ProcessAutoReply: Salla products fetch failed for place_order', ['error' => $e->getMessage()]);
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
                'escalation_reason' => "hard_keyword_override: {$hardEscalation['matched_keyword']}"
            ]);

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

        // Step 3: Build AI Context
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

        // Build business profile context separate from uploaded knowledge
        $businessProfileContext = '';
        if ($business) {
            // Business name
            $businessProfileContext .= "BUSINESS PROFILE\n";
            $businessProfileContext .= "================\n";
            $businessName = $business->business_name ?? 'our business';
            $businessProfileContext .= "Business Name: {$businessName}\n";

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
                        if (is_array($faq) && isset($faq['question'], $faq['answer'])) {
                            $faqItems[] = "Q: {$faq['question']}\nA: {$faq['answer']}";
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

            // AI instructions
            if (!empty($business->ai_instructions)) {
                $businessProfileContext .= "AI Instructions: {$business->ai_instructions}\n";
            }

            // Reply style
            if (!empty($business->reply_style)) {
                $businessProfileContext .= "Reply Style: {$business->reply_style}\n";
            }

            $businessProfileContext .= "\n";
        }

        $context = [
            'business_name'  => $channel->business?->business_name ?? 'our business',
            'platform'       => $channel->type,
            'language'       => $detectedLanguage,
            'salla_exclusive_mode' => $isSallaFlow,
            'business_profile' => $isSallaFlow ? '' : $businessProfileContext,
            'knowledge_base' => (function() use ($business, $message, $isSallaFlow) {
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

                // 2. Search for relevant chunks
                $relevantChunks = $vectorSearch->search($queryEmbedding, $business->id, 5);

                if (empty($relevantChunks)) {
                    return '';
                }

                $knowledgeText = '';
                foreach ($relevantChunks as $index => $chunk) {
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
            // CRITICAL ISSUE — REPLY-TO-PRODUCT CONTEXT: the product the
            // customer deterministically referenced by replying to its image,
            // if any. See getUltimateSystemPrompt() — when present, the AI
            // must never ask "which product" again.
            'referenced_product'       => $referencedProduct,
            // Active partial order state (persisted across turns so the AI
            // knows which fields have already been collected and won't re-ask).
            'cart'                     => !empty($checkoutState) ? $checkoutState : null,
        ];

        // Step 4: Single AI Call with JSON Output
        $aiResult = AICapabilitiesService::callAIWithJSON($message->content, $context);

        if (!$aiResult['success']) {
            Log::error('ProcessAutoReply: AI call failed', [
                'conversation_id' => $conversation->id,
                'fallback_used' => true
            ]);

            // Fallback + Escalate
            $conversation->update([
                'requires_human' => true,
                'escalated_at' => now(),
                'escalation_reason' => 'ai_failure_fallback'
            ]);

            // Provide a user-friendly fallback message
            $fallbackMessage = $aiResult['reply'] ?? "I apologize, but I'm having technical difficulties right now. A human agent will be with you shortly to help with your request.";
            
            $replyMessage = Message::create([
                'conversation_id' => $message->conversation_id,
                'content' => $fallbackMessage,
                'direction' => 'outbound',
                'status' => 'auto',
                'is_ai' => false,
                'source' => 'fallback',
                'send_status' => 'pending',
            ]);

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
        //
        // DO NOT escalate for:
        //   - general questions (even if AI doesn't know the answer)
        //   - information_missing (AI should just say it doesn't know and ask if user wants human)
        //   - low_confidence (AI should still reply, just be honest about uncertainty)

        $escalationReason    = $aiResult['escalation_reason'] ?? 'none';
        $needsEscalation     = $aiResult['needs_escalation'] ?? false;
        $intent              = $aiResult['intent'] ?? 'unknown';

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

        if ($needsEscalation && in_array($escalationReason, $hardEscalationReasons)) {
            // Hard escalation: AI has a concrete reason to hand off
            $shouldEscalate = true;
            $decisionReason = "ai_hard_escalation: {$escalationReason}";
        } else {
            // Never escalate for other reasons - trust the AI's reply
            $shouldEscalate = false;
            $decisionReason = 'auto_reply_trust_ai_response';
        }

        // Log full AI decision for diagnostics
        Log::info('ProcessAutoReply: AI decision', [
            'conversation_id'  => $conversation->id,
            'intent'           => $intent,
            'confidence'       => round(($aiResult['confidence'] ?? 0) * 100, 1),
            'needs_escalation' => $needsEscalation,
            'escalation_reason' => $escalationReason,
        ]);

        Log::info('ProcessAutoReply: final decision', [
            'conversation_id' => $conversation->id,
            'decision'        => $shouldEscalate ? 'ESCALATE' : 'AUTO_REPLY',
            'decision_reason' => $decisionReason,
        ]);

        if ($shouldEscalate) {
            $conversation->update([
                'requires_human'   => true,
                'escalated_at'     => now(),
                'escalation_reason' => "{$decisionReason} (intent={$intent})",
            ]);

            $escalationMessage = "Sure 👍 I'm connecting you with a team member now. Please wait a moment.";
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

                // Send email notification about escalation
                $notificationService = new \App\Services\NotificationService();
                $notificationService->escalation(
                    $channel->user_id,
                    $decisionReason,
                    $conversation->id
                );
            }
            $this->sendReply($channel, $conversation, $replyMessage);
            return;
        }

        // Step 6: Send AI Response
        $aiResponse = $aiResult['reply'];

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
            $imagesActuallySent = $this->sendImagesToCustomer($channel, $conversation, $productImageItems);
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
                'original_reply'   => substr($aiResponse, 0, 200),
            ]);
            $aiResponse = $detectedLanguage === 'arabic'
                ? "عذرًا، لا أستطيع إرسال صور المنتج الآن. سأقوم بتوصيلك بأحد أفراد الفريق ليرسلها لك مباشرة 🙏"
                : "I'm sorry, I'm not able to send product photos right now. Let me connect you with a team member who can send them directly 🙏";
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
            'confidence_score' => self::normalizeConfidenceScore($aiResult['confidence'] ?? null),
            'detected_dialect' => in_array($detectedDialect, ['egyptian', 'gulf', 'msa', 'mixed']) ? $detectedDialect : null,
            'intent' => $intent,
            'detected_language' => $detectedLanguage,
        ]);

        Log::info('ProcessAutoReply: AI reply saved', ['message_id' => $replyMessage->id]);

        // ── CHECKOUT STATE PERSISTENCE ───────────────────────────────────────
        // Keep the partial order state alive across turns so the product identity
        // and any already-collected customer fields (name/phone/address) survive
        // when downstream calls fail or the customer sends multiple reply messages.
        //
        // Rules:
        //  • On place_order turns: initialize or merge state (product + collected fields)
        //  • When the AI reply confirms the order was placed: clear state
        //  • All other intents: leave state untouched (may be a Salla-flow continuation)
        if ($intent === 'place_order' && $referencedProduct) {
            $aiReplyLower   = mb_strtolower($aiResponse);
            $orderConfirmed = str_contains($aiReplyLower, 'order has been placed')
                || str_contains($aiReplyLower, 'تم تأكيد طلبك')
                || str_contains($aiReplyLower, 'تم الطلب');

            if ($orderConfirmed) {
                // Order successfully placed — clear state
                $conversation->update(['checkout_state' => null]);
                Log::info('ProcessAutoReply: cleared checkout_state after order confirmed', [
                    'conversation_id' => $conversation->id,
                ]);
            } else {
                // Merge the known product identity with any newly-provided customer fields
                // extracted directly from the customer's message text.
                $incoming = $message->content;

                // Simple heuristic: extract phone if message contains a digit sequence
                $newPhone = null;
                if (preg_match('/(?:\+?[0-9]{8,15})/', preg_replace('/\s+/', '', $incoming), $pm)) {
                    $newPhone = $pm[0];
                }

                $newState = array_merge(
                    is_array($checkoutState) ? $checkoutState : [],
                    array_filter([
                        'salla_product_id' => $referencedProduct['salla_product_id'] ?? ($checkoutState['salla_product_id'] ?? null),
                        'sku'              => $referencedProduct['sku']              ?? ($checkoutState['sku']              ?? null),
                        'product_name'     => $referencedProduct['name']             ?? ($checkoutState['product_name']     ?? null),
                        'product_price'    => $referencedProduct['price']            ?? ($checkoutState['product_price']    ?? null),
                        'product_currency' => $referencedProduct['currency']         ?? ($checkoutState['product_currency'] ?? 'SAR'),
                        // Phone extracted from this message (non-null only if found)
                        'customer_phone'   => $newPhone ?? ($checkoutState['customer_phone'] ?? null),
                        // Preserve started_at timestamp from first turn
                        'started_at'       => $checkoutState['started_at'] ?? now()->toISOString(),
                    ], fn($v) => $v !== null)
                );

                $conversation->update(['checkout_state' => $newState]);
                Log::info('ProcessAutoReply: updated checkout_state for place_order turn', [
                    'conversation_id' => $conversation->id,
                    'product_id'      => $newState['salla_product_id'] ?? null,
                    'has_phone'       => !empty($newState['customer_phone']),
                ]);
            }
        } elseif ($checkoutState && in_array($intent, ['greeting', 'escalation', 'order_status'])) {
            // Clear stale checkout state if the conversation moved to a clearly different flow
            $conversation->update(['checkout_state' => null]);
        }
        // ── END CHECKOUT STATE PERSISTENCE ───────────────────────────────────


        // Increment the monthly counter now that the message is confirmed saved
        cache()->increment($cacheKey);

        if ($channel->user_id) {
            broadcast(new \App\Events\MessageReceived($replyMessage, $message->conversation, $channel->user_id));
        }

        // Images (if any) were already sent above, before the text was composed,
        // so sendReply now only needs to deliver the (possibly overridden) text.
        $sendSuccess = $this->sendReply($channel, $message->conversation, $replyMessage);
        
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

        // ── SEQUENCE TRIGGER INTEGRATION ───────────────────────────────────────
        // After AI reply is sent, check if conversation should be enrolled in any sequences
        // This is a small integration that doesn't affect the main AI processing logic
        try {
            $sequenceTriggerService = new SequenceTriggerService();
            $sequenceTriggerService->checkAndEnrollForMessageReceived($conversation);
        } catch (\Exception $e) {
            Log::error('ProcessAutoReply: Failed to check sequence enrollment', [
                'conversation_id' => $conversation->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

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
    private function sendImagesToCustomer(Channel $channel, Conversation $conversation, array $productItems): int
    {
        $sentCount = 0;
        $recipientId = $conversation->sender_id;

        foreach ($productItems as $item) {
            $imageUrl = $item['image_url'] ?? null;
            if (empty($imageUrl)) {
                continue;
            }

            $sentMessageId = null;

            try {
                if ($channel->type === 'whatsapp') {
                    $whatsappService = new EvolutionApiService();
                    // EvolutionApiService::makeRequest() throws on any non-2xx
                    // response after retries -- reaching the line below without
                    // an exception means Evolution accepted the send.
                    $response = $whatsappService->sendMediaMessage($channel->page_id, $recipientId, $imageUrl, '', 'image');
                    $sentMessageId = $response['key']['id'] ?? null;
                    $sentCount++;
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
                    } else {
                        Log::error('ProcessAutoReply: image send failed (Facebook/Instagram)', [
                            'channel_type' => $channel->type,
                            'image_url'    => $imageUrl,
                            'status'       => $response->status(),
                            'body'         => $response->body(),
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
                    'image_url'    => $imageUrl,
                    'error'        => $e->getMessage(),
                ]);
            }

            // Persist the deterministic message→product mapping ONLY when we
            // actually have the outgoing message id — without it a later reply
            // can never be traced back to this specific image.
            if ($sentMessageId) {
                try {
                    \App\Models\ProductMessageMap::updateOrCreate(
                        [
                            'conversation_id'      => $conversation->id,
                            'whatsapp_message_id'  => $sentMessageId,
                        ],
                        [
                            'channel_id'       => $channel->id,
                            'salla_product_id' => isset($item['id']) ? (string) $item['id'] : null,
                            'sku'              => $item['sku'] ?? null,
                            'product_name'     => $item['name'] ?? null,
                            'product_price'    => isset($item['price']) ? (string) $item['price'] : null,
                            'currency'         => $item['currency'] ?? null,
                            'image_url'        => $imageUrl,
                        ]
                    );

                    // Deliberate INFO-level success log: its absence was what
                    // made the original bug hard to diagnose from production
                    // logs alone (image sends looked "successful" with no way
                    // to tell whether the mapping was actually persisted).
                    Log::info('ProcessAutoReply: persisted product message map', [
                        'conversation_id'     => $conversation->id,
                        'whatsapp_message_id' => $sentMessageId,
                        'salla_product_id'    => isset($item['id']) ? (string) $item['id'] : null,
                    ]);
                } catch (\Exception $e) {
                    Log::error('ProcessAutoReply: failed to persist product message map', [
                        'conversation_id' => $conversation->id,
                        'message_id'      => $sentMessageId,
                        'error'           => $e->getMessage(),
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

        try {
            $success = false;

            if ($channel->type === 'facebook') {
                $success = $this->sendFacebookReply($channel, $senderId, $content, $images);
            } elseif ($channel->type === 'instagram') {
                $success = $this->sendInstagramReply($channel, $senderId, $content, $images);
            } elseif ($channel->type === 'gmail') {
                $success = $this->sendGmailReply($channel, $conversation, $content);
            } elseif ($channel->type === 'whatsapp') {
                $success = $this->sendWhatsAppReply($channel, $senderId, $content, $images);
            } elseif ($channel->type === 'telegram') {
                $success = $this->sendTelegramReply($channel, $senderId, $content);
            } elseif ($channel->type === 'tiktok') {
                $success = $this->sendTikTokReply($channel, $senderId, $content);
            }

            if ($success) {
                $replyMessage->update(['send_status' => 'sent']);
                Log::info('ProcessAutoReply: reply sent successfully', [
                    'platform' => $channel->type,
                    'message_id' => $replyMessage->id,
                    'ai_replies_count' => $this->aiRepliesCount,
                ]);
            } else {
                $replyMessage->update(['send_status' => 'failed']);
                // Decrement counter since message failed to send
                if ($this->aiRepliesCount > 0) {
                    $cacheKey = "user_{$channel->user_id}_ai_replies_" . now()->format('Y-m');
                    Cache::decrement($cacheKey);
                }
                Log::error('ProcessAutoReply: reply send failed', [
                    'platform' => $channel->type,
                    'message_id' => $replyMessage->id,
                ]);
            }

            return $success;

        } catch (\Exception $e) {
            $replyMessage->update(['send_status' => 'failed']);
            // Decrement counter since message failed to send
            if ($this->aiRepliesCount > 0) {
                $cacheKey = "user_{$channel->user_id}_ai_replies_" . now()->format('Y-m');
                Cache::decrement($cacheKey);
            }
            Log::error('ProcessAutoReply: send reply exception', [
                'error' => $e->getMessage(),
                'platform' => $channel->type,
            ]);
            return false;
        }
    }

    private function sendFacebookReply(Channel $channel, string $recipientId, string $message, array $images = []): bool
    {
        // The Channel model's accessor already decrypts access_token — do NOT
        // call decrypt() again or the token will be double-decrypted and corrupted.
        $accessToken = $channel->access_token;
        $baseUrl = "https://graph.facebook.com/v19.0/me/messages?access_token={$accessToken}";

        // Send images first
        foreach ($images as $imageUrl) {
            Http::timeout(10)->post($baseUrl, [
                'recipient' => ['id' => $recipientId],
                'message' => [
                    'attachment' => [
                        'type' => 'image',
                        'payload' => [
                            'url' => $imageUrl,
                            'is_reusable' => true
                        ]
                    ]
                ],
            ]);
        }

        // Send text message
        $response = Http::timeout(10)
            ->post($baseUrl, [
                'recipient' => ['id' => $recipientId],
                'message' => ['text' => $message],
            ]);

        if (!$response->successful()) {
            Log::error('ProcessAutoReply: Facebook send failed', [
                'status' => $response->status(),
                'body' => $response->json(),
                'recipient' => $recipientId,
            ]);
        }

        return $response->successful();
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

    private function sendInstagramReply(Channel $channel, string $recipientId, string $message, array $images = []): bool
    {
        // Instagram uses the same API as Facebook with the page access token
        return $this->sendFacebookReply($channel, $recipientId, $message, $images);
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
            Log::error('ProcessAutoReply: Gmail send exception', ['error' => $e->getMessage()]);
            return false;
        }
    }

    private function sendWhatsAppReply(Channel $channel, string $recipientId, string $message, array $images = []): bool
    {
        try {
            $whatsappService = new EvolutionApiService();
            $instanceName = $channel->page_id; // We store instance_name in page_id for WhatsApp

            // Send images first
            foreach ($images as $imageUrl) {
                $whatsappService->sendMediaMessage(
                    $instanceName,
                    $recipientId,
                    $imageUrl,
                    '', // No caption, send standalone image
                    'image'
                );
            }

            // Send the actual text message
            $response = $whatsappService->sendTextMessage($instanceName, $recipientId, $message);

            if (isset($response['key']['id'])) {
                // Persist to whatsapp_messages (legacy table) only when an
                // instance row exists. In test environments or during the brief
                // window between Channel creation and WhatsAppInstance creation,
                // the instance may not exist yet — skipping the insert is correct
                // because the message was already saved in unified inbox.
                $instance = \App\Models\WhatsAppInstance::where('instance_name', $instanceName)->first();

                if ($instance) {
                    \App\Models\WhatsAppMessage::create([
                        'whatsapp_instance_id' => $instance->id,
                        'user_id'              => $channel->user_id,
                        'message_id'           => $response['key']['id'] ?? null,
                        'remote_message_id'    => $response['key']['id'] ?? null,
                        'direction'            => 'outgoing',
                        'from_phone'           => null,
                        'from_name'            => null,
                        'to_phone'             => $recipientId,
                        'body'                 => $message,
                        'message_type'         => 'text',
                        'media'                => null,
                        'metadata'             => ['evolution_message_id' => $response['key']['id'] ?? null],
                        'status'               => 'sent',
                        'sent_at'              => now(),
                    ]);
                } else {
                    Log::warning('ProcessAutoReply: WhatsAppInstance not found for legacy message record — skipping (unified inbox record already saved)', [
                        'instance_name' => $instanceName,
                        'channel_id'    => $channel->id,
                    ]);
                }

                return true;
            }

            return false;

        } catch (\Exception $e) {
            Log::error('ProcessAutoReply: WhatsApp send exception', ['error' => $e->getMessage()]);
            return false;
        }
    }

    private function sendTelegramReply(Channel $channel, string $chatId, string $message): bool
    {
        try {
            $botToken = decrypt($channel->access_token);
            $telegramService = new \App\Services\TelegramService();
            
            $success = $telegramService->sendMessage($botToken, $chatId, $message);

            if (!$success) {
                Log::error('ProcessAutoReply: Telegram send failed', [
                    'chat_id' => $chatId,
                ]);
            }

            return $success;

        } catch (\Exception $e) {
            Log::error('ProcessAutoReply: Telegram send exception', ['error' => $e->getMessage()]);
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
            Log::error('ProcessAutoReply: TikTok send exception', ['error' => $e->getMessage()]);
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
                'error' => $e->getMessage(),
                'user_id' => $user->id
            ]);
        }
    }
}
