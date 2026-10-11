<?php

namespace App\Services;

use App\Models\AiReplyAudit;
use App\Models\Channel;
use App\Models\Message;

class AiReplyAuditService
{
    /**
     * Save decision provenance without copying prompts, customer text, or credentials.
     * The message's conversation is authoritative for tenant and connection scope.
     */
    public function record(Message $message, array $details = []): AiReplyAudit
    {
        $conversation = $message->conversation;
        $bot = $conversation?->bot;
        if ($bot && (int) $bot->business_profile_id !== (int) $conversation->business_id) {
            $bot = null;
        }
        $commerceConnectionId = $details['commerce_connection_id'] ?? $conversation->ecommerce_connection_id;
        if ($commerceConnectionId && !Channel::query()
            ->whereKey($commerceConnectionId)
            ->where('business_id', $conversation->business_id)
            ->exists()) {
            $commerceConnectionId = null;
        }

        return AiReplyAudit::updateOrCreate(
            ['message_id' => $message->id],
            [
                'business_id' => $conversation->business_id,
                'conversation_id' => $conversation->id,
                'bot_id' => $bot?->id,
                'commerce_connection_id' => $commerceConnectionId,
                'agent_version' => $details['agent_version'] ?? $bot?->updated_at?->toIso8601String(),
                'knowledge_file_ids' => array_values(array_unique(array_map('intval', $details['knowledge_file_ids'] ?? []))),
                'knowledge_chunk_ids' => array_values(array_unique(array_map('intval', $details['knowledge_chunk_ids'] ?? []))),
                'prompt_version' => $details['prompt_version'] ?? config('services.ai.prompt_version', 'v1'),
                'ai_provider' => $details['ai_provider'] ?? null,
                'ai_model' => $details['ai_model'] ?? null,
                'confidence' => $message->confidence_score,
                'escalation_decision' => (bool) ($details['escalation_decision'] ?? false),
                'escalation_reason' => $details['escalation_reason'] ?? null,
                'consent_basis' => $details['consent_basis'] ?? 'service_reply_to_customer_inbound',
            ]
        );
    }
}
