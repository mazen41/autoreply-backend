<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AiReplyAudit;
use App\Models\Message;
use App\Services\ActiveBusiness;
use Illuminate\Http\Request;

class AiReplyAuditController extends Controller
{
    public function show(Request $request, int $messageId, ActiveBusiness $activeBusiness)
    {
        // `write` is deliberately limited to business owners and managers.
        $business = $activeBusiness->resolve($request, permission: 'write');
        $message = Message::query()
            ->whereKey($messageId)
            ->where('is_ai', true)
            ->where('direction', 'outbound')
            ->whereHas('conversation', fn ($query) => $query->where('business_id', $business->id))
            ->firstOrFail();

        $audit = AiReplyAudit::query()
            ->with(['bot:id,name', 'commerceConnection:id,type,page_name'])
            ->where('message_id', $message->id)
            ->where('business_id', $business->id)
            ->firstOrFail();

        return response()->json([
            'agent' => $audit->bot ? ['id' => $audit->bot->id, 'name' => $audit->bot->name] : null,
            'agent_version' => $audit->agent_version,
            'commerce_connection' => $audit->commerceConnection ? [
                'id' => $audit->commerceConnection->id,
                'provider' => $audit->commerceConnection->type,
                'name' => $audit->commerceConnection->page_name,
            ] : null,
            'knowledge_file_ids' => $audit->knowledge_file_ids ?? [],
            'knowledge_chunk_ids' => $audit->knowledge_chunk_ids ?? [],
            'prompt_version' => $audit->prompt_version,
            'ai_provider' => $audit->ai_provider,
            'ai_model' => $audit->ai_model,
            'confidence' => $audit->confidence,
            'escalation_decision' => $audit->escalation_decision,
            'escalation_reason' => $audit->escalation_reason,
            'consent_basis' => $audit->consent_basis,
        ]);
    }
}
