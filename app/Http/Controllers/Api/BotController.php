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
    /**
     * Resolve the business profile for the current request.
     *
     * Supports multi-business users via X-Business-Id header or business_id input.
     * Falls back to the user's primary business profile without creating duplicates.
     */
    private function getResolvedBusinessProfile(Request $request): BusinessProfile
    {
        $user = $request->user();

        // Check for explicit business ID from header or input
        $requestedBusinessId = $request->header('X-Business-Id') ?? $request->input('business_id');

        if ($requestedBusinessId) {
            $requestedBusinessId = (int) $requestedBusinessId;

            // Verify user has access to this business (as owner or team member)
            $hasAccess = BusinessProfile::where('id', $requestedBusinessId)
                ->where('user_id', $user->id)
                ->exists() ||
                \App\Models\TeamMember::where('business_id', $requestedBusinessId)
                    ->where('user_id', $user->id)
                    ->where('is_active', true)
                    ->exists();

            if ($hasAccess) {
                return BusinessProfile::findOrFail($requestedBusinessId);
            }

            // If no access, fall through to default behavior
        }

        // Fallback: get or create the user's primary business profile
        return BusinessProfile::firstOrCreate(['user_id' => $user->id]);
    }

    public function index(Request $request)
    {
        $business = $this->getResolvedBusinessProfile($request);
        $bots = Bot::where('business_profile_id', $business->id)
            ->with(['channels:id,type,page_name,page_id', 'ecommerceChannel:id,type,page_name,page_id', 'knowledgeAssignments'])
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'bots' => $bots,
        ]);
    }

    public function store(Request $request)
    {
        $business = $this->getResolvedBusinessProfile($request);

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
            'ecommerce_channel_id' => 'nullable|integer',
            'channel_ids' => 'nullable|array',
            'channel_ids.*' => 'integer',
            'knowledge_assignments' => 'nullable|array',
            'knowledge_assignments.*.file_id' => 'required|integer',
            'knowledge_assignments.*.channel_id' => 'nullable|integer',
        ]);

        $bot = Bot::create([
            'business_profile_id' => $business->id,
            'ecommerce_channel_id' => $request->ecommerce_channel_id,
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

            // Enforce is_primary integrity: only one primary bot per channel
            if (!empty($primaryIds)) {
                // Remove is_primary from all other bots for channels where this bot is being set as primary
                \DB::table('bot_channels')
                    ->whereIn('channel_id', $primaryIds)
                    ->where('bot_id', '!=', $bot->id)
                    ->update(['is_primary' => false]);
            }

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
            'bot' => $bot->load(['channels:id,type,page_name,page_id', 'ecommerceChannel:id,type,page_name,page_id', 'knowledgeAssignments']),
        ], 201);
    }

    public function show(Request $request, $id)
    {
        $business = $this->getResolvedBusinessProfile($request);
        $bot = Bot::where('business_profile_id', $business->id)
            ->with(['channels', 'ecommerceChannel', 'knowledgeAssignments.knowledgeFile', 'knowledgeAssignments.channel'])
            ->findOrFail($id);

        return response()->json(['bot' => $bot]);
    }

    public function update(Request $request, $id)
    {
        $business = $this->getResolvedBusinessProfile($request);
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
            'ecommerce_channel_id' => 'nullable|integer',
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
            'ecommerce_channel_id',
        ]));

        if ($request->has('channel_ids')) {
            $validChannelIds = Channel::whereIn('id', $request->channel_ids ?? [])
                ->where('user_id', $request->user()->id)
                ->pluck('id')
                ->toArray();

            $primaryIds = $request->primary_channel_ids ?? [];

            // Enforce is_primary integrity: only one primary bot per channel
            if (!empty($primaryIds)) {
                \DB::table('bot_channels')
                    ->whereIn('channel_id', $primaryIds)
                    ->where('bot_id', '!=', $bot->id)
                    ->update(['is_primary' => false]);
            }

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
        $business = $this->getResolvedBusinessProfile($request);
        $bot = Bot::where('business_profile_id', $business->id)->findOrFail($id);
        $bot->delete();

        return response()->json(['message' => 'Bot deleted successfully']);
    }

    // ── BOT-CHANNEL MANAGEMENT ───────────────────────────────────────────────

    /**
     * GET /api/bots/{id}/channels — list channels assigned to this bot
     */
    public function channels(Request $request, $id)
    {
        $business = $this->getResolvedBusinessProfile($request);
        $bot = Bot::where('business_profile_id', $business->id)
            ->with(['channels:id,type,page_name,page_id,status'])
            ->findOrFail($id);

        return response()->json([
            'bot_id' => $bot->id,
            'channels' => $bot->channels->map(fn ($ch) => [
                'id' => $ch->id,
                'type' => $ch->type,
                'page_name' => $ch->page_name,
                'page_id' => $ch->page_id,
                'status' => $ch->status,
                'is_primary' => $ch->pivot->is_primary ?? false,
            ]),
        ]);
    }

    /**
     * POST /api/bots/{id}/channels — attach channels to this bot
     */
    public function attachChannels(Request $request, $id)
    {
        $business = $this->getResolvedBusinessProfile($request);
        $bot = Bot::where('business_profile_id', $business->id)->findOrFail($id);

        $request->validate([
            'channel_ids' => 'required|array|min:1',
            'channel_ids.*' => 'integer',
            'primary_channel_ids' => 'nullable|array',
            'primary_channel_ids.*' => 'integer',
        ]);

        $validChannelIds = Channel::whereIn('id', $request->channel_ids)
            ->where('user_id', $request->user()->id)
            ->pluck('id')
            ->toArray();

        if (empty($validChannelIds)) {
            return response()->json(['error' => 'No valid channels found'], 422);
        }

        $primaryIds = $request->primary_channel_ids ?? [];

        // Enforce is_primary integrity: only one primary bot per channel
        if (!empty($primaryIds)) {
            \DB::table('bot_channels')
                ->whereIn('channel_id', $primaryIds)
                ->where('bot_id', '!=', $bot->id)
                ->update(['is_primary' => false]);
        }

        $syncData = [];
        foreach ($validChannelIds as $chId) {
            $syncData[$chId] = ['is_primary' => in_array($chId, $primaryIds)];
        }
        $bot->channels()->syncWithoutDetaching($syncData);

        return response()->json([
            'message' => 'Channels attached successfully',
            'bot' => $bot->load(['channels:id,type,page_name,page_id']),
        ]);
    }

    /**
     * DELETE /api/bots/{id}/channels/{channelId} — detach a channel from this bot
     */
    public function detachChannel(Request $request, $id, $channelId)
    {
        $business = $this->getResolvedBusinessProfile($request);
        $bot = Bot::where('business_profile_id', $business->id)->findOrFail($id);

        $channel = Channel::where('id', $channelId)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        $bot->channels()->detach($channel->id);

        return response()->json(['message' => 'Channel detached successfully']);
    }

    // ── BOT-KNOWLEDGE ASSIGNMENT MANAGEMENT ──────────────────────────────────

    /**
     * GET /api/bots/{id}/knowledge — list knowledge files assigned to this bot
     */
    public function knowledge(Request $request, $id)
    {
        $business = $this->getResolvedBusinessProfile($request);
        $bot = Bot::where('business_profile_id', $business->id)
            ->with(['knowledgeAssignments.knowledgeFile', 'knowledgeAssignments.channel'])
            ->findOrFail($id);

        return response()->json([
            'bot_id' => $bot->id,
            'knowledge_assignments' => $bot->knowledgeAssignments->map(fn ($a) => [
                'id' => $a->id,
                'file_id' => $a->business_knowledge_file_id,
                'file_name' => $a->knowledgeFile?->file_name,
                'channel_id' => $a->channel_id,
                'channel_type' => $a->channel?->type,
            ]),
        ]);
    }

    /**
     * POST /api/bots/{id}/knowledge — assign knowledge files to this bot
     */
    public function assignKnowledge(Request $request, $id)
    {
        $business = $this->getResolvedBusinessProfile($request);
        $bot = Bot::where('business_profile_id', $business->id)->findOrFail($id);

        $request->validate([
            'assignments' => 'required|array|min:1',
            'assignments.*.file_id' => 'required|integer|exists:business_knowledge_files,id',
            'assignments.*.channel_id' => 'nullable|integer|exists:channels,id',
        ]);

        foreach ($request->assignments as $assign) {
            // Verify the file belongs to the user's business
            $file = \App\Models\BusinessKnowledgeFile::where('id', $assign['file_id'])
                ->where('business_id', $business->id)
                ->firstOrFail();

            BotKnowledgeAssignment::create([
                'bot_id' => $bot->id,
                'business_knowledge_file_id' => $file->id,
                'channel_id' => $assign['channel_id'] ?? null,
            ]);
        }

        return response()->json([
            'message' => 'Knowledge assigned successfully',
            'bot' => $bot->load(['knowledgeAssignments.knowledgeFile']),
        ]);
    }

    /**
     * DELETE /api/bots/{id}/knowledge/{fileId} — remove a knowledge assignment
     */
    public function removeKnowledge(Request $request, $id, $fileId)
    {
        $business = $this->getResolvedBusinessProfile($request);
        $bot = Bot::where('business_profile_id', $business->id)->findOrFail($id);

        $assignment = BotKnowledgeAssignment::where('bot_id', $bot->id)
            ->where('business_knowledge_file_id', $fileId)
            ->firstOrFail();

        $assignment->delete();

        return response()->json(['message' => 'Knowledge assignment removed successfully']);
    }
}
