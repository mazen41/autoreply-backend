<?php

namespace App\Http\Controllers;

use App\Services\AICapabilitiesService;
use App\Models\Channel;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\KnowledgeChunker;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class N8nIntegrationController extends Controller
{
    /**
     * Check rate limit for user
     */
    public function checkRateLimit(Request $request)
    {
        $userId = $request->input('user_id');
        $businessId = $request->input('business_id', 'default');

        $rateLimitCheck = AICapabilitiesService::checkRateLimit($userId, $businessId);

        return response()->json($rateLimitCheck);
    }

    /**
     * Detect language from message
     */
    public function detectLanguage(Request $request)
    {
        $message = $request->input('message');

        $languageDetection = AICapabilitiesService::detectLanguage($message);

        return response()->json($languageDetection);
    }

    /**
     * Get conversation memory
     */
    public function getConversationMemory(Request $request)
    {
        $conversationId = $request->input('conversation_id');

        $memory = AICapabilitiesService::getConversationMemory($conversationId);

        return response()->json($memory);
    }

    /**
     * Ultimate AI Process - Single Call with JSON Output
     */
    public function ultimateAIProcess(Request $request)
    {
        try {
            $message = $request->input('message');
            $platform = $request->input('platform', 'whatsapp');
            $conversationId = $request->input('conversation_id');
            $userId = $request->input('user_id');
            $businessId = $request->input('business_id');
            $language = $request->input('language', 'english');
            $memory = $request->input('memory', []);

            // Get business knowledge base
            $business = null;
            if ($businessId) {
                $business = \App\Models\BusinessProfile::where('id', $businessId)->first();
            }

            // Build knowledge base with chunking and relevance
            $knowledgeBase = '';
            if ($business && $message) {
                foreach ($business->knowledgeFiles()->get() as $file) {
                    $fullText = $file->extracted_text;

                    // Chunk the file content to preserve sentence boundaries
                    if (strlen($fullText) > 2000) {
                        $chunks = KnowledgeChunker::chunkText($fullText, 2000, 200);

                        // Get relevant chunks based on the user's message
                        $relevantChunks = KnowledgeChunker::getRelevantChunks(
                            $chunks,
                            $message,
                            3
                        );

                        $knowledgeBase .= "\n\n--- File: {$file->filename} (Relevant Chunks) ---\n";
                        $knowledgeBase .= KnowledgeChunker::formatChunksForPrompt($relevantChunks, $file->filename);
                    } else {
                        $knowledgeBase .= "\n\n--- File: {$file->filename} ---\n";
                        $knowledgeBase .= $fullText;
                    }
                }
            }

            // Build context for AI
            $context = [
                'business_name' => $business?->business_name ?? 'our business',
                'platform' => $platform,
                'language' => $language,
                'knowledge_base' => $knowledgeBase ?? '',
                'memory' => $memory
            ];

            // Call Ultimate AI with JSON output
            $aiResult = AICapabilitiesService::callAIWithJSON($message, $context);

            // Update conversation memory if needed
            if ($aiResult['success']) {
                AICapabilitiesService::updateConversationMemory($conversationId, [
                    'last_intent' => $aiResult['intent'],
                    'last_response' => $aiResult['reply']
                ]);
            }

            return response()->json($aiResult);

        } catch (\Exception $e) {
            Log::error('Ultimate AI Process failed', [
                'error' => $e->getMessage(),
                'request' => $request->all()
            ]);

            return response()->json([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Trigger escalation
     */
    public function triggerEscalation(Request $request)
    {
        try {
            $conversationId = $request->input('conversation_id');
            $reason = $request->input('reason', 'unknown');
            $priority = $request->input('priority', 'normal');
            $userId = $request->input('user_id');
            $intent = $request->input('intent');
            $confidence = $request->input('confidence');

            // Update conversation
            $conversation = \App\Models\Conversation::find($conversationId);
            if ($conversation) {
                $conversation->update([
                    'requires_human' => true,
                    'escalated_at' => now(),
                    'escalation_reason' => "{$reason}: intent={$intent}, confidence={$confidence}"
                ]);
            }

            // Priority classification
            $priorityData = AICapabilitiesService::classifyEscalationPriority([
                'user_tier' => 'regular', // Could be enhanced with user lookup
                'sentiment' => 'neutral',
                'reason' => $reason
            ]);

            // Log escalation
            Log::info('Escalation triggered via n8n', [
                'conversation_id' => $conversationId,
                'reason' => $reason,
                'priority' => $priority,
                'priority_data' => $priorityData
            ]);

            return response()->json([
                'success' => true,
                'escalated' => true,
                'priority' => $priorityData['priority'],
                'response_time_sla' => $priorityData['response_time_sla']
            ]);

        } catch (\Exception $e) {
            Log::error('Escalation trigger failed', [
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * n8n calls this with a message_id.
     * Runs ProcessAutoReply synchronously and returns what was sent.
     * This is the single entry point for n8n — no fragmented calls needed.
     */
    public function processMessage(Request $request)
    {
        try {
            $messageId = $request->input('message_id');

            if (!$messageId) {
                return response()->json(['success' => false, 'error' => 'message_id is required'], 422);
            }

            $message = Message::with(['conversation.channel', 'conversation.channel.business', 'conversation.channel.user'])
                ->find($messageId);

            if (!$message) {
                return response()->json(['success' => false, 'error' => 'Message not found'], 404);
            }

            // Run the full ProcessAutoReply job synchronously (not queued)
            // so n8n gets the result back in the same HTTP response
            $job = new \App\Jobs\ProcessAutoReply($messageId);
            $job->handle();

            // Find the outbound reply that was just created
            $reply = Message::where('conversation_id', $message->conversation_id)
                ->where('direction', 'outbound')
                ->orderBy('created_at', 'desc')
                ->first();

            return response()->json([
                'success'        => true,
                'message_id'     => $messageId,
                'reply'          => $reply?->content,
                'reply_id'       => $reply?->id,
                'send_status'    => $reply?->send_status,
                'is_ai'          => $reply?->is_ai,
                'source'         => $reply?->source,
                'requires_human' => $message->conversation->requires_human ?? false,
            ]);

        } catch (\Exception $e) {
            Log::error('N8n processMessage failed', [
                'error'      => $e->getMessage(),
                'message_id' => $request->input('message_id'),
            ]);

            return response()->json([
                'success' => false,
                'error'   => $e->getMessage()
            ], 500);
        }
    }
}