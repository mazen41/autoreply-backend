<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Bot;
use App\Models\BotKnowledgeAssignment;
use App\Models\BusinessProfile;
use App\Models\Channel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class BotController extends Controller
{
    private function getBusinessProfile(Request $request): BusinessProfile
    {
        return BusinessProfile::firstOrCreate(['user_id' => $request->user()->id]);
    }

    public function index(Request $request)
    {
        $business = $this->getBusinessProfile($request);
        $bots = Bot::where('business_profile_id', $business->id)
            ->with(['channels:id,type,page_name,page_id', 'knowledgeAssignments'])
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'bots' => $bots,
        ]);
    }

    public function store(Request $request)
    {
        $business = $this->getBusinessProfile($request);

        $request->validate([
            'name' => 'required|string|max:255',
            'status' => 'sometimes|in:active,inactive,draft',
            'ai_provider' => 'nullable|string',
            'ai_model' => 'nullable|string',
            'ai_instructions' => 'nullable|string',
            'ai_tone_style' => 'nullable|array',
            'reply_style' => 'nullable|string',
            'ai_confidence_threshold' => 'nullable|numeric|min:0|max:1',
            'escalation_config' => 'nullable|array',
            'channel_ids' => 'nullable|array',
            'channel_ids.*' => 'integer',
            'knowledge_assignments' => 'nullable|array',
            'knowledge_assignments.*.file_id' => 'required|integer',
            'knowledge_assignments.*.channel_id' => 'nullable|integer',
        ]);

        $bot = Bot::create([
            'business_profile_id' => $business->id,
            'name' => $request->name,
            'status' => $request->status ?? 'active',
            'ai_provider' => $request->ai_provider ?? 'gemini',
            'ai_model' => $request->ai_model ?? 'gemini-2.5-flash',
            'ai_instructions' => $request->ai_instructions,
            'ai_tone_style' => $request->ai_tone_style,
            'reply_style' => $request->reply_style,
            'ai_confidence_threshold' => $request->ai_confidence_threshold ?? 0.80,
            'escalation_config' => $request->escalation_config,
        ]);

        if (!empty($request->channel_ids)) {
            // Only attach channels belonging to the user's business
            $validChannelIds = Channel::whereIn('id', $request->channel_ids)
                ->where('user_id', $request->user()->id)
                ->pluck('id')
                ->toArray();

            $primaryIds = $request->primary_channel_ids ?? [];
            $syncData = [];
            foreach ($validChannelIds as $chId) {
                $syncData[$chId] = ['is_primary' => in_array($chId, $primaryIds)];
            }
            $bot->channels()->sync($syncData);
        }

        if (!empty($request->knowledge_assignments)) {
            foreach ($request->knowledge_assignments as $assign) {
                BotKnowledgeAssignment::create([
                    'bot_id' => $bot->id,
                    'business_knowledge_file_id' => $assign['file_id'],
                    'channel_id' => $assign['channel_id'] ?? null,
                ]);
            }
        }

        return response()->json([
            'message' => 'Bot created successfully',
            'bot' => $bot->load(['channels:id,type,page_name,page_id', 'knowledgeAssignments']),
        ], 201);
    }

    public function show(Request $request, $id)
    {
        $business = $this->getBusinessProfile($request);
        $bot = Bot::where('business_profile_id', $business->id)
            ->with(['channels', 'knowledgeAssignments.knowledgeFile', 'knowledgeAssignments.channel'])
            ->findOrFail($id);

        return response()->json(['bot' => $bot]);
    }

    public function update(Request $request, $id)
    {
        $business = $this->getBusinessProfile($request);
        $bot = Bot::where('business_profile_id', $business->id)->findOrFail($id);

        $request->validate([
            'name' => 'sometimes|string|max:255',
            'status' => 'sometimes|in:active,inactive,draft',
            'ai_provider' => 'nullable|string',
            'ai_model' => 'nullable|string',
            'ai_instructions' => 'nullable|string',
            'ai_tone_style' => 'nullable|array',
            'reply_style' => 'nullable|string',
            'ai_confidence_threshold' => 'nullable|numeric|min:0|max:1',
            'escalation_config' => 'nullable|array',
            'channel_ids' => 'nullable|array',
            'knowledge_assignments' => 'nullable|array',
        ]);

        $bot->update($request->only([
            'name',
            'status',
            'ai_provider',
            'ai_model',
            'ai_instructions',
            'ai_tone_style',
            'reply_style',
            'ai_confidence_threshold',
            'escalation_config',
        ]));

        if ($request->has('channel_ids')) {
            $validChannelIds = Channel::whereIn('id', $request->channel_ids ?? [])
                ->where('user_id', $request->user()->id)
                ->pluck('id')
                ->toArray();

            $primaryIds = $request->primary_channel_ids ?? [];
            $syncData = [];
            foreach ($validChannelIds as $chId) {
                $syncData[$chId] = ['is_primary' => in_array($chId, $primaryIds)];
            }
            $bot->channels()->sync($syncData);
        }

        if ($request->has('knowledge_assignments')) {
            BotKnowledgeAssignment::where('bot_id', $bot->id)->delete();
            foreach ($request->knowledge_assignments ?? [] as $assign) {
                BotKnowledgeAssignment::create([
                    'bot_id' => $bot->id,
                    'business_knowledge_file_id' => $assign['file_id'],
                    'channel_id' => $assign['channel_id'] ?? null,
                ]);
            }
        }

        return response()->json([
            'message' => 'Bot updated successfully',
            'bot' => $bot->load(['channels:id,type,page_name,page_id', 'knowledgeAssignments']),
        ]);
    }

    public function destroy(Request $request, $id)
    {
        $business = $this->getBusinessProfile($request);
        $bot = Bot::where('business_profile_id', $business->id)->findOrFail($id);
        $bot->delete();

        return response()->json(['message' => 'Bot deleted successfully']);
    }
}
