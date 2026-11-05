<?php

namespace App\Services;

use App\Models\Conversation;
use App\Models\AgentCoachingLog;
use App\Models\BusinessProfile;
use Illuminate\Support\Facades\Log;

class AgentCoachingService
{
    /**
     * Evaluate a completed human-handled conversation and generate coaching feedback.
     *
     * @param Conversation $conversation
     * @return array Coaching evaluation result
     */
    public static function evaluateConversation(Conversation $conversation): array
    {
        // Only evaluate conversations that were escalated and closed
        if (!$conversation->requires_human || $conversation->status !== 'closed') {
            return ['error' => 'Conversation is not eligible for coaching evaluation'];
        }

        // Get all messages in the conversation
        $messages = $conversation->messages()
            ->orderBy('created_at', 'asc')
            ->get();

        if ($messages->isEmpty()) {
            return ['error' => 'No messages to evaluate'];
        }

        // Build conversation transcript
        $transcript = $messages->map(function ($msg) {
            $role = $msg->direction === 'inbound' ? 'customer' : 'agent';
            return "[{$role}]: {$msg->content}";
        })->implode("\n");

        // Get agent info
        $agent = $conversation->assignedAgent;
        $agentName = $agent?->name ?? 'Unknown Agent';

        // Run LLM analysis
        $business = $conversation->business;
        if (!$business) return ['error' => 'Business scope is unavailable for AI credit billing'];
        $analysis = self::runLlmAnalysis($transcript, $agentName, $business, (int) $conversation->id);

        // Save coaching log
        $coachingLog = AgentCoachingLog::create([
            'conversation_id' => $conversation->id,
            'agent_id' => $conversation->assigned_agent_id,
            'business_id' => $conversation->business_id,
            'empathy_score' => $analysis['empathy_score'] ?? 5,
            'sla_adherence_score' => $analysis['sla_adherence_score'] ?? 5,
            'accuracy_score' => $analysis['accuracy_score'] ?? 5,
            'constructive_feedback' => $analysis['constructive_feedback'] ?? '',
            'overall_score' => $analysis['overall_score'] ?? 5,
        ]);

        Log::info('Agent coaching evaluation completed', [
            'conversation_id' => $conversation->id,
            'agent_id' => $conversation->assigned_agent_id,
            'overall_score' => $analysis['overall_score'] ?? 5,
        ]);

        return [
            'coaching_log_id' => $coachingLog->id,
            'agent_name' => $agentName,
            'empathy_score' => $analysis['empathy_score'] ?? 5,
            'sla_adherence_score' => $analysis['sla_adherence_score'] ?? 5,
            'accuracy_score' => $analysis['accuracy_score'] ?? 5,
            'overall_score' => $analysis['overall_score'] ?? 5,
            'constructive_feedback' => $analysis['constructive_feedback'] ?? '',
        ];
    }

    /**
     * Run LLM analysis on conversation transcript.
     */
    private static function runLlmAnalysis(string $transcript, string $agentName, BusinessProfile $business, int $conversationId): array
    {
        $prompt = <<<PROMPT
You are an expert customer service coach. Analyze the following conversation between a customer and agent "{$agentName}".

Evaluate the agent's performance on these criteria (1-10 scale):
1. Empathy: Did the agent show understanding and care for the customer's situation?
2. SLA Adherence: Did the agent respond in a timely manner and follow procedures?
3. Accuracy: Was the agent's information correct and helpful?

Also provide constructive feedback with specific coaching tips.

Conversation:
{$transcript}

Respond in JSON format:
{
  "empathy_score": <1-10>,
  "sla_adherence_score": <1-10>,
  "accuracy_score": <1-10>,
  "overall_score": <1-10>,
  "constructive_feedback": "<detailed coaching tips>"
}
PROMPT;

        try {
            $result = app(AiGateway::class)->chat($business, 'summarization', 'Return only a JSON object matching the requested coaching schema.', [['role' => 'user', 'content' => $prompt]], 500, 'agent-coaching:' . $conversationId, ['conversation_id' => $conversationId]);
            $content = $result['content'] ?? '{}';
            $parsed = json_decode($content, true) ?? [];

            return [
                'empathy_score' => max(1, min(10, $parsed['empathy_score'] ?? 5)),
                'sla_adherence_score' => max(1, min(10, $parsed['sla_adherence_score'] ?? 5)),
                'accuracy_score' => max(1, min(10, $parsed['accuracy_score'] ?? 5)),
                'overall_score' => max(1, min(10, $parsed['overall_score'] ?? 5)),
                'constructive_feedback' => $parsed['constructive_feedback'] ?? 'No feedback available',
            ];

        } catch (\Throwable $e) {
            Log::error('Agent coaching LLM analysis error', ['exception_type' => get_class($e), 'conversation_id' => $conversationId]);
            return self::getDefaultScores($e instanceof \App\Exceptions\InsufficientAiCreditsException ? 'Insufficient AI credits' : 'AI analysis unavailable');
        }
    }

    /**
     * Get default scores when analysis fails.
     */
    private static function getDefaultScores(string $error): array
    {
        return [
            'empathy_score' => 5,
            'sla_adherence_score' => 5,
            'accuracy_score' => 5,
            'overall_score' => 5,
            'constructive_feedback' => "Analysis unavailable: {$error}",
        ];
    }

    /**
     * Get coaching summary for an agent.
     */
    public static function getAgentCoachingSummary(int $agentId): array
    {
        $logs = AgentCoachingLog::where('agent_id', $agentId)
            ->orderBy('created_at', 'desc')
            ->get();

        if ($logs->isEmpty()) {
            return ['message' => 'No coaching data available for this agent'];
        }

        return [
            'total_evaluations' => $logs->count(),
            'avg_empathy_score' => round($logs->avg('empathy_score'), 1),
            'avg_sla_adherence_score' => round($logs->avg('sla_adherence_score'), 1),
            'avg_accuracy_score' => round($logs->avg('accuracy_score'), 1),
            'avg_overall_score' => round($logs->avg('overall_score'), 1),
            'recent_evaluations' => $logs->take(5)->toArray(),
        ];
    }
}
