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

        // Check business hours and send away message if closed
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
        // Keywords that indicate customer wants to PLACE a new order / buy
        $placeOrderKeywords = [
            'place an order','i want to order','i want to buy','purchase','i\'d like to order',
            'عايز اطلب','ابي اشتري','ابغا اشتري','اشتري','اطلب',
            'show me products','what do you sell','المنتجات','منتجاتكم',
        ];

        $isOrderStatus = false;
        $isPlaceOrder  = false;

        foreach ($orderStatusKeywords as $kw) {
            if (str_contains($msgLower, $kw)) { $isOrderStatus = true; break; }
        }
        foreach ($placeOrderKeywords as $kw) {
            if (str_contains($msgLower, $kw)) { $isPlaceOrder = true; break; }
        }

        // Find Salla channel for this user
        $sallaChannel = Channel::where('user_id', $user->id)
            ->where('type', 'salla')
            ->where('status', 'connected')
            ->first();

        if ($sallaChannel) {

            // ── ORDER STATUS FLOW ────────────────────────────────────────────
            if ($isOrderStatus) {
                $rawPhone = preg_replace('/[^0-9]/', '', $conversation->sender_id ?? '');

                // WhatsApp: auto-lookup by sender phone
                if ($channel->type === 'whatsapp' && $rawPhone) {
                    try {
                        $sallaService = new SallaService();
                        $order = $sallaService->getLatestOrderByPhone($sallaChannel->access_token, $rawPhone);
                        if ($order) {
                            $sallaContext = $sallaService->formatOrderForAI($order);
                            Log::info('ProcessAutoReply: Salla order found for WhatsApp sender', [
                                'phone'    => $rawPhone,
                                'order_id' => $order['id'] ?? null,
                            ]);
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
                try {
                    $sallaService = new SallaService();
                    $productsRaw  = $sallaService->getProducts($sallaChannel->access_token, ['per_page' => 10]);
                    $products     = $productsRaw['data'] ?? [];

                    // Normalise product list for AI context
                    $productsContext = array_map(function ($p) {
                        return [
                            'id'        => $p['id']                    ?? null,
                            'name'      => $p['name']                  ?? 'Unknown',
                            'price'     => $p['price']['amount']       ?? $p['price'] ?? '?',
                            'currency'  => $p['price']['currency_code'] ?? 'SAR',
                            'available' => ($p['quantity'] ?? 1) > 0,
                            'url'       => $p['urls']['customer']      ?? null,
                        ];
                    }, $products);

                    Log::info('ProcessAutoReply: Salla products loaded for place_order', [
                        'count' => count($productsContext),
                    ]);
                } catch (\Exception $e) {
                    Log::warning('ProcessAutoReply: Salla products fetch failed', ['error' => $e->getMessage()]);
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
            'business_profile' => $businessProfileContext,
            'knowledge_base' => (function() use ($business, $message) {
                if (!$business) {
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

        // Increment the monthly counter now that the message is confirmed saved
        cache()->increment($cacheKey);

        if ($channel->user_id) {
            broadcast(new \App\Events\MessageReceived($replyMessage, $message->conversation, $channel->user_id));
        }

        // Send reply through platform
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

    
    private function sendReply(Channel $channel, Conversation $conversation, Message $replyMessage): bool
    {
        $senderId = $conversation->sender_id;
        $content = $replyMessage->content;

        try {
            $success = false;

            if ($channel->type === 'facebook') {
                $success = $this->sendFacebookReply($channel, $senderId, $content);
            } elseif ($channel->type === 'instagram') {
                $success = $this->sendInstagramReply($channel, $senderId, $content);
            } elseif ($channel->type === 'gmail') {
                $success = $this->sendGmailReply($channel, $conversation, $content);
            } elseif ($channel->type === 'whatsapp') {
                $success = $this->sendWhatsAppReply($channel, $senderId, $content);
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

    private function sendFacebookReply(Channel $channel, string $recipientId, string $message): bool
    {
        // The Channel model's accessor already decrypts access_token — do NOT
        // call decrypt() again or the token will be double-decrypted and corrupted.
        $accessToken = $channel->access_token;
        $url = "https://graph.facebook.com/v19.0/me/messages?access_token={$accessToken}";

        $response = Http::timeout(10)
            ->post($url, [
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

    private function sendInstagramReply(Channel $channel, string $recipientId, string $message): bool
    {
        // Instagram uses the same API as Facebook with the page access token
        return $this->sendFacebookReply($channel, $recipientId, $message);
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

            $message = new GmailMessage();
            $message->setRaw($encoded);
            if ($threadId) {
                $message->setThreadId($threadId);
            }

            $gmail->users_messages->send('me', $message);
            return true;

        } catch (\Exception $e) {
            Log::error('ProcessAutoReply: Gmail send exception', ['error' => $e->getMessage()]);
            return false;
        }
    }

    private function sendWhatsAppReply(Channel $channel, string $recipientId, string $message): bool
    {
        try {
            $whatsappService = new EvolutionApiService();
            $instanceName = $channel->page_id; // We store instance_name in page_id for WhatsApp

            $response = $whatsappService->sendTextMessage($instanceName, $recipientId, $message);

            if (isset($response['key']['id'])) {
                // Also save to WhatsApp messages table for legacy compatibility
                \App\Models\WhatsAppMessage::create([
                    'whatsapp_instance_id' => \App\Models\WhatsAppInstance::where('instance_name', $instanceName)->first()?->id,
                    'user_id' => $channel->user_id,
                    'message_id' => $response['key']['id'] ?? null,
                    'remote_message_id' => $response['key']['id'] ?? null,
                    'direction' => 'outgoing',
                    'from_phone' => null,
                    'from_name' => null,
                    'to_phone' => $recipientId,
                    'body' => $message,
                    'message_type' => 'text',
                    'media' => null,
                    'metadata' => ['evolution_response' => $response],
                    'status' => 'sent',
                    'sent_at' => now(),
                ]);

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

            // Log as critical for monitoring
            Log::critical('AI Failure Notification', [
                'user_id' => $user->id,
                'user_email' => $user->email,
                'conversation_id' => $conversation->id,
                'error' => $error,
                'timestamp' => now()->toISOString(),
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
