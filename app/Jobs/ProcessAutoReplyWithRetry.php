<?php

namespace App\Jobs;

use App\Models\Channel;
use App\Models\Conversation;
use App\Models\Message;
use App\Http\Controllers\GmailController;
use App\Services\EvolutionApiService;
use App\Services\KnowledgeChunker;
use Google\Service\Gmail;
use Google\Service\Gmail\Message as GmailMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ProcessAutoReplyWithRetry implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 5; // Increased from 3 to 5 attempts
    public $backoff = [10, 30, 60, 120, 300]; // Progressive backoff: 10s, 30s, 1m, 2m, 5m
    public $timeout = 120; // 2 minutes timeout per attempt

    private ?string $lastAIProvider = null;
    private ?string $lastAIModel = null;

    public function __construct(public int $messageId)
    {
        $this->onQueue('high'); // Use high-priority queue for AI replies
    }

    public function handle(): void
    {
        Log::info('ProcessAutoReplyWithRetry job started', [
            'message_id' => $this->messageId,
            'attempt' => $this->attempts()
        ]);

        $message = Message::with(['conversation.channel', 'conversation.channel.business', 'conversation.channel.user'])
            ->find($this->messageId);

        if (!$message) {
            Log::warning('ProcessAutoReplyWithRetry: message not found', ['message_id' => $this->messageId]);
            return;
        }

        $channel = $message->conversation->channel;
        $conversation = $message->conversation;

        if (!$channel || !$channel->ai_enabled) {
            Log::info('ProcessAutoReplyWithRetry: AI not enabled for channel', ['channel_id' => $channel?->id]);
            return;
        }

        if (!$conversation || !$conversation->ai_enabled) {
            Log::info('ProcessAutoReplyWithRetry: AI not enabled for conversation', ['conversation_id' => $conversation?->id]);
            return;
        }

        if ($channel->status !== 'connected') {
            Log::warning('ProcessAutoReplyWithRetry: channel not connected', ['channel_id' => $channel->id, 'status' => $channel->status]);
            return;
        }

        $user = $channel->user;
        if (!$user) {
            Log::warning('ProcessAutoReplyWithRetry: channel has no user', ['channel_id' => $channel->id]);
            return;
        }

        $business = $conversation->business;
        if (!$business) {
            Log::warning('ProcessAutoReplyWithRetry: business unavailable for usage enforcement', [
                'conversation_id' => $conversation->id,
            ]);
            return;
        }

        $usageMeter = app(\App\Services\UsageMeter::class);
        $aiUsage = $usageMeter->canUse($business, \App\Services\UsageMeter::AI_REPLIES);
        $outboundUsage = $usageMeter->canUse($business, \App\Services\UsageMeter::OUTBOUND_MESSAGES);
        if (!$aiUsage['allowed'] || !$outboundUsage['allowed']) {
            Log::info('ProcessAutoReplyWithRetry: business plan usage limit reached', [
                'business_id' => $business->id,
                'conversation_id' => $conversation->id,
                'metric' => !$aiUsage['allowed'] ? 'ai_replies' : 'outbound_messages',
            ]);
            return;
        }

        try {
            // Build system prompt from business profile
            $systemPrompt = $this->buildSystemPrompt($channel, $message->content);

            // Get last 10 messages for context
            $contextMessages = Message::where('conversation_id', $message->conversation_id)
                ->orderBy('created_at', 'desc')
                ->take(10)
                ->get()
                ->reverse()
                ->map(fn($m) => [
                    'role' => $m->direction === 'inbound' ? 'user' : 'assistant',
                    'content' => $m->content,
                ])
                ->toArray();

            $aiResponse = $this->callConfiguredAI($systemPrompt, $contextMessages);

            if (!$aiResponse) {
                Log::error('ProcessAutoReplyWithRetry: configured AI providers returned no response', ['message_id' => $this->messageId]);
                throw new \Exception('AI providers returned no response');
            }

            // Save AI response as outbound message
            $replyMessage = Message::create([
                'conversation_id' => $message->conversation_id,
                'content' => $aiResponse,
                'direction' => 'outbound',
                'status' => 'auto',
                'is_ai' => true,
                'source' => 'ai',
                'send_status' => 'pending',
            ]);

            try {
                app(\App\Services\AiReplyAuditService::class)->record($replyMessage, [
                    'prompt_version' => 'legacy-retry',
                    'ai_provider' => $this->lastAIProvider,
                    'ai_model' => $this->lastAIModel,
                    'escalation_reason' => 'legacy_retry_processor',
                ]);
            } catch (\Throwable $e) {
                Log::error('ProcessAutoReplyWithRetry: could not persist AI reply audit', [
                    'message_id' => $replyMessage->id,
                    'exception_type' => get_class($e),
                ]);
            }

            Log::info('ProcessAutoReplyWithRetry: AI reply saved', ['message_id' => $replyMessage->id]);

            if ($channel->user_id) {
                broadcast(new \App\Events\MessageReceived($replyMessage, $message->conversation, $channel->user_id));
            }

            // Send reply through platform
            $this->sendReply($channel, $message->conversation, $replyMessage);

        } catch (\Exception $e) {
            Log::error('ProcessAutoReplyWithRetry: error during processing', [
                'message_id' => $this->messageId,
                'attempt' => $this->attempts(),
                'exception_type' => get_class($e),
            ]);

            // Mark message as failed if this is the last attempt
            if ($this->attempts() >= $this->tries) {
                $message->update(['send_status' => 'failed']);
                Log::error('ProcessAutoReplyWithRetry: permanent failure after all attempts', [
                    'message_id' => $this->messageId,
                    'attempts' => $this->attempts()
                ]);
            }

            throw $e; // Re-throw to trigger retry mechanism
        }
    }

    private function buildSystemPrompt(Channel $channel, ?string $message = null): string
    {
        $business = $channel->business;

        // Fallback: If channel is missing business_id, try to find the user's business profile
        if (!$business && $channel->user_id) {
            $business = \App\Models\BusinessProfile::where('user_id', $channel->user_id)->first();
        }

        if (!$business) {
            return "You are an AI customer support assistant. Answer questions truthfully. If you do not know the answer, politely state that you don't know and offer to connect them with a human agent.";
        }

        $workingDays = is_array($business->working_days) ? implode(', ', $business->working_days) : ($business->working_days ?? 'N/A');
        $workingHours = "{$workingDays} from {$business->working_from} to {$business->working_to}";

        $faqsText = '';
        if (!empty($business->faqs)) {
            $faqs = is_array($business->faqs) ? $business->faqs : json_decode($business->faqs, true);
            if (is_array($faqs)) {
                foreach ($faqs as $faq) {
                    $q = $faq['question'] ?? $faq['q'] ?? '';
                    $a = $faq['answer'] ?? $faq['a'] ?? '';
                    if ($q && $a) $faqsText .= "Q: {$q}\nA: {$a}\n";
                }
            }
        }

        // Build knowledge base from individual files with proper chunking
        $knowledgeText = '';
        foreach ($business->knowledgeFiles()->get() as $file) {
            $fullText = $file->extracted_text;

            // Chunk the file content to preserve sentence boundaries
            if (strlen($fullText) > 2000) {
                $chunks = KnowledgeChunker::chunkText($fullText, 2000, 200);

                // Get relevant chunks based on the user's message
                $relevantChunks = KnowledgeChunker::getRelevantChunks(
                    $chunks,
                    $message ?? '', // Use actual message content for relevance
                    3
                );

                $knowledgeText .= "\n\n--- File: {$file->filename} (Relevant Chunks) ---\n";
                $knowledgeText .= KnowledgeChunker::formatChunksForPrompt($relevantChunks, $file->filename);
            } else {
                $knowledgeText .= "\n\n--- File: {$file->filename} ---\n";
                $knowledgeText .= $fullText;
            }
        }

        $prompt = "You are the AI assistant for {$business->business_name}, a {$business->business_type} business.\n";
        $prompt .= "Your job is to answer customer questions accurately using ONLY the information provided below.\n\n";

        $prompt .= "### BUSINESS INFORMATION ###\n";
        $prompt .= "- Business Name: {$business->business_name}\n";
        $prompt .= "- Business Type: {$business->business_type}\n";
        $prompt .= "- Location: {$business->city}, {$business->country}\n";
        $prompt .= "- Contact Phone: {$business->phone}\n";
        $prompt .= "- Working Hours: {$workingHours}\n";
        $prompt .= "- Services/Products: {$business->services}\n";

        if ($faqsText) {
            $prompt .= "\n### FREQUENTLY ASKED QUESTIONS ###\n{$faqsText}\n";
        }

        // Add knowledge base from uploaded files
        if (!empty($knowledgeText)) {
            $prompt .= "\n### KNOWLEDGE BASE & DOCUMENTATION ###\n{$knowledgeText}\n";
        }

        // Add custom AI instructions
        if (!empty($business->ai_instructions)) {
            $prompt .= "\n### CUSTOM INSTRUCTIONS ###\n{$business->ai_instructions}\n";
        }

        $prompt .= "\n### CRITICAL RULES ###\n";
        $prompt .= "1. NEVER say vague filler like 'I am here to assist you with any questions' as a substitute for a real answer.\n";
        $prompt .= "2. If you do not know the answer based on the provided information, DO NOT guess or make things up. Honestly say you don't have that information and offer to have a human follow up.\n";
        $prompt .= "3. Actively use the conversation history context provided. Do not repeat or contradict yourself.\n";
        $prompt .= "4. Keep replies concise, clear, and friendly.\n";
        $prompt .= "5. Reply in the same language the customer used (Arabic or English).\n";

        return $prompt;
    }

    private function callConfiguredAI(string $systemPrompt, array $contextMessages): ?string
    {
        $this->lastAIProvider = null;
        $this->lastAIModel = null;

        // Try OpenAI first
        $openaiKey = config('services.openai.api_key');
        if ($openaiKey) {
            try {
                $response = Http::timeout(30)
                    ->withHeaders([
                        'Authorization' => 'Bearer ' . $openaiKey,
                        'Content-Type' => 'application/json',
                    ])
                    ->post('https://api.openai.com/v1/chat/completions', [
                        'model' => config('services.openai.model', 'gpt-3.5-turbo'),
                        'messages' => [
                            ['role' => 'system', 'content' => $systemPrompt],
                            ...$contextMessages,
                        ],
                        'max_tokens' => 500,
                        'temperature' => 0.7,
                    ]);

                if ($response->successful()) {
                    $data = $response->json();
                    $this->lastAIProvider = 'openai';
                    $this->lastAIModel = config('services.openai.model', 'gpt-3.5-turbo');
                    return $data['choices'][0]['message']['content'] ?? null;
                }
            } catch (\Exception $e) {
                Log::warning('OpenAI API call failed', ['exception_type' => get_class($e)]);
            }
        }

        // Fallback to a simple rule-based response
        $this->lastAIProvider = 'rule_fallback';
        $this->lastAIModel = 'keyword_rules';
        return $this->getFallbackResponse($contextMessages);
    }

    private function getFallbackResponse(array $contextMessages): ?string
    {
        $lastMessage = end($contextMessages);
        if (!$lastMessage) {
            return "I apologize, but I'm having trouble processing your request right now. A human agent will assist you shortly.";
        }

        $content = strtolower($lastMessage['content'] ?? '');

        // Simple keyword-based fallback responses
        if (str_contains($content, 'price') || str_contains($content, 'cost') || str_contains($content, 'سعر')) {
            return "For pricing information, please contact our support team. They'll be happy to provide you with our current packages and offers.";
        }

        if (str_contains($content, 'hour') || str_contains($content, 'time') || str_contains($content, 'ساعة') || str_contains($content, 'وقت')) {
            return "Our working hours vary by location. Please contact us directly for our current schedule.";
        }

        if (str_contains($content, 'help') || str_contains($content, 'assist') || str_contains($content, 'مساعدة')) {
            return "I'm here to help! Please let me know what you need assistance with, and I'll do my best to help you or connect you with the right person.";
        }

        return "Thank you for your message. Our team is reviewing your request and will get back to you shortly.";
    }

    private function sendReply(Channel $channel, Conversation $conversation, Message $replyMessage): void
    {
        app(\App\Services\ChannelMessageService::class)->sendMessage($channel, $conversation, $replyMessage);
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('ProcessAutoReplyWithRetry job failed permanently', [
            'message_id' => $this->messageId,
            'attempts' => $this->attempts(),
            'exception_type' => get_class($exception),
        ]);

        // Optionally notify admin or send to dead letter queue
        // This could send a notification to the user that AI failed
    }
}
