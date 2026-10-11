<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Bot;
use App\Models\BotKnowledgeAssignment;
use App\Models\BusinessProfile;
use App\Models\Channel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class BotController extends Controller
{
    /**
     * Resolve the business profile for the current request.
     *
     * Resolve the explicitly selected business and enforce the caller's role.
     */
    private function getResolvedBusinessProfile(Request $request, string $permission = 'read'): BusinessProfile
    {
        $user = $request->user();

        return app(\App\Services\ActiveBusiness::class)->resolve(
            $request,
            $user,
            $permission,
        );
    }

    public function index(Request $request)
    {
        $business = $this->getResolvedBusinessProfile($request, 'read');
        $bots = Bot::where('business_profile_id', $business->id)
            ->with([
                'channels' => fn ($query) => $query
                    ->where('channels.business_id', $business->id)
                    ->select('channels.id', 'channels.type', 'channels.page_name', 'channels.page_id', 'channels.status', 'channels.ai_enabled'),
                'channels.bots:id,status,business_profile_id',
                'ecommerceChannel:id,type,page_name,page_id',
                'ecommerceConnections:id,type,page_name,page_id,status',
                'knowledgeAssignments',
            ])
            ->orderBy('created_at', 'desc')
            ->get();

        $bots->each(function (Bot $bot): void {
            $effectiveChannels = 0;
            foreach ($bot->channels as $channel) {
                $activeBots = $channel->bots
                    ->where('business_profile_id', $bot->business_profile_id)
                    ->where('status', 'active')
                    ->values();
                $primaryBot = $activeBots->first(fn ($candidate) => (bool) ($candidate->pivot->is_primary ?? false));
                $resolvedBot = $activeBots->count() === 1
                    ? $activeBots->first()
                    : ($activeBots->count() > 1 ? $primaryBot : null);
                $channelEffective = $bot->status === 'active'
                    && (bool) $channel->ai_enabled
                    && $channel->status === 'connected'
                    && $resolvedBot?->id === $bot->id;
                $channel->setAttribute('agent_ai_enabled', $channelEffective);
                $channel->setAttribute('agent_ai_pause_reason', $channelEffective
                    ? null
                    : ($bot->status !== 'active'
                        ? 'agent_inactive'
                        : (!(bool) $channel->ai_enabled
                            ? 'channel_ai_paused'
                            : ($channel->status !== 'connected'
                                ? 'channel_not_connected'
                                : ($resolvedBot ? 'another_agent_selected' : 'no_active_agent')))));

                if ($channelEffective) {
                    $effectiveChannels++;
                }
            }

            $bot->setAttribute('effective_ai_enabled', $effectiveChannels > 0);
            $bot->setAttribute('effective_ai_channels', $effectiveChannels);
            $bot->setAttribute('ai_pause_reason', $effectiveChannels > 0
                ? null
                : ($bot->status !== 'active' ? 'agent_inactive' : 'no_active_channel'));
        });

        return response()->json([
            'bots' => $bots,
        ]);
    }

    public function store(Request $request)
    {
        $business = $this->getResolvedBusinessProfile($request, 'write');

        $request->validate([
            'name' => 'required|string|max:255',
            'status' => 'sometimes|in:active,inactive,draft',
            'ai_provider' => 'nullable|in:gemini,groq',
            'ai_model' => 'nullable|string|max:120',
            'ai_instructions' => 'nullable|string',
            'ai_tone_style' => 'nullable|array',
            'reply_style' => 'nullable|string',
            'ai_confidence_threshold' => 'nullable|numeric|min:0|max:1',
            'escalation_config' => 'nullable|array',
            'escalation_config.keywords' => 'sometimes|array|max:30',
            'escalation_config.keywords.*' => 'string|max:80',
            'escalation_config.intents' => 'sometimes|array|max:20',
            'escalation_config.intents.*' => 'string|max:80',
            'escalation_config.fallback_message' => 'nullable|string|max:1000',
            'ecommerce_channel_id' => 'nullable|integer',
            'ecommerce_connection_ids' => 'nullable|array',
            'ecommerce_connection_ids.*' => 'integer',
            'default_ecommerce_connection_id' => 'nullable|integer',
            'channel_ids' => 'nullable|array',
            'channel_ids.*' => 'integer',
            'channel_store_routing' => 'nullable|array',
            'channel_store_routing.*' => 'nullable|integer',
            'primary_channel_ids' => 'nullable|array',
            'primary_channel_ids.*' => 'integer',
            'knowledge_assignments' => 'nullable|array',
            'knowledge_assignments.*.file_id' => 'required|integer',
            'knowledge_assignments.*.channel_id' => 'nullable|integer',
        ]);

        $connectionIds = $request->input('ecommerce_connection_ids');
        if ($connectionIds === null && $request->filled('ecommerce_channel_id')) {
            $connectionIds = [(int) $request->ecommerce_channel_id];
        }
        $defaultConnectionId = $request->input('default_ecommerce_connection_id', $request->ecommerce_channel_id);
        $validCommerceIds = $this->validateCommerceConnections($connectionIds ?? [], $defaultConnectionId, $business);
        $knowledgeAssignments = $this->validateKnowledgeAssignments(
            $request->input('knowledge_assignments', []),
            $business
        );

        $validChannelIds = Channel::whereIn('id', $request->input('channel_ids', []))
            ->where('business_id', $business->id)->pluck('id')->all();
        $channelStoreRouting = $this->validateChannelStoreRouting(
            $request->input('channel_store_routing', []),
            $validChannelIds,
            $validCommerceIds,
            $business
        );

        $bot = DB::transaction(function () use ($business, $defaultConnectionId, $request, $validCommerceIds, $knowledgeAssignments, $validChannelIds, $channelStoreRouting) {
        $bot = Bot::create([
            'business_profile_id' => $business->id,
            'ecommerce_channel_id' => $defaultConnectionId,
            'name' => $request->name,
            'status' => $request->status ?? 'active',
            'ai_provider' => $request->input('ai_provider'),
            'ai_model' => $request->input('ai_model'),
            'ai_instructions' => $request->ai_instructions,
            'ai_tone_style' => $request->ai_tone_style,
            'reply_style' => $request->reply_style,
            'ai_confidence_threshold' => $request->ai_confidence_threshold ?? 0.80,
            'escalation_config' => $request->escalation_config,
        ]);

        $this->syncCommerceConnections($bot, $validCommerceIds, $defaultConnectionId);

        if (!empty($validChannelIds)) {
            // Only attach channels belonging to the user's business
            $primaryIds = array_values(array_intersect(
                array_map('intval', $request->input('primary_channel_ids', [])),
                array_map('intval', $validChannelIds)
            ));

            // Enforce is_primary integrity: only one primary bot per channel
            if (!empty($primaryIds)) {
                // Remove is_primary from all other bots for channels where this bot is being set as primary
                \DB::table('bot_channels')
                    ->whereIn('channel_id', $primaryIds)
                    ->whereIn('channel_id', $validChannelIds)
                    ->where('bot_id', '!=', $bot->id)
                    ->update(['is_primary' => false]);
            }

            $syncData = [];
            foreach ($validChannelIds as $chId) {
                $syncData[$chId] = [
                    'is_primary' => in_array($chId, $primaryIds),
                    'ecommerce_connection_id' => $channelStoreRouting[$chId] ?? null,
                ];
            }
            $bot->channels()->sync($syncData);
        }

        if (!empty($knowledgeAssignments)) {
            foreach ($knowledgeAssignments as $assign) {
                BotKnowledgeAssignment::create([
                    'bot_id' => $bot->id,
                    'business_knowledge_file_id' => $assign['file_id'],
                    'channel_id' => $assign['channel_id'] ?? null,
                ]);
            }
        }

        return $bot;
        });

        return response()->json([
            'message' => 'Bot created successfully',
            'bot' => $bot->load(['channels:id,type,page_name,page_id', 'ecommerceChannel:id,type,page_name,page_id', 'ecommerceConnections:id,type,page_name,page_id,status', 'knowledgeAssignments']),
        ], 201);
    }

    public function show(Request $request, $id)
    {
        $business = $this->getResolvedBusinessProfile($request, 'read');
        $bot = Bot::where('business_profile_id', $business->id)
            ->with(['channels', 'ecommerceChannel', 'ecommerceConnections', 'knowledgeAssignments.knowledgeFile', 'knowledgeAssignments.channel'])
            ->findOrFail($id);

        return response()->json(['bot' => $bot]);
    }

    public function update(Request $request, $id)
    {
        $business = $this->getResolvedBusinessProfile($request, 'write');
        $bot = Bot::where('business_profile_id', $business->id)->findOrFail($id);

        $request->validate([
            'name' => 'sometimes|string|max:255',
            'status' => 'sometimes|in:active,inactive,draft',
            'ai_provider' => 'nullable|in:gemini,groq',
            'ai_model' => 'nullable|string|max:120',
            'ai_instructions' => 'nullable|string',
            'ai_tone_style' => 'nullable|array',
            'reply_style' => 'nullable|string',
            'ai_confidence_threshold' => 'nullable|numeric|min:0|max:1',
            'escalation_config' => 'nullable|array',
            'escalation_config.keywords' => 'sometimes|array|max:30',
            'escalation_config.keywords.*' => 'string|max:80',
            'escalation_config.intents' => 'sometimes|array|max:20',
            'escalation_config.intents.*' => 'string|max:80',
            'escalation_config.fallback_message' => 'nullable|string|max:1000',
            'ecommerce_channel_id' => 'nullable|integer',
            'ecommerce_connection_ids' => 'sometimes|array',
            'ecommerce_connection_ids.*' => 'integer',
            'default_ecommerce_connection_id' => 'nullable|integer',
            'channel_ids' => 'nullable|array',
            'channel_ids.*' => 'integer',
            'channel_store_routing' => 'sometimes|array',
            'channel_store_routing.*' => 'nullable|integer',
            'primary_channel_ids' => 'sometimes|array',
            'primary_channel_ids.*' => 'integer',
            'knowledge_assignments' => 'nullable|array',
            'knowledge_assignments.*.file_id' => 'required_with:knowledge_assignments|integer',
            'knowledge_assignments.*.channel_id' => 'nullable|integer',
        ]);

        $updates = $request->only([
            'name',
            'status',
            'ai_provider',
            'ai_model',
            'ai_instructions',
            'ai_tone_style',
            'reply_style',
            'ai_confidence_threshold',
            'escalation_config',
        ]);
        $connectionFieldsProvided = $request->exists('ecommerce_connection_ids')
            || $request->exists('ecommerce_channel_id')
            || $request->exists('default_ecommerce_connection_id');
        $validCommerceIds = null;
        $defaultConnectionId = null;
        if ($connectionFieldsProvided) {
            $connectionIds = $request->input('ecommerce_connection_ids');
            if ($connectionIds === null && $request->filled('ecommerce_channel_id')) {
                $connectionIds = [(int) $request->ecommerce_channel_id];
            }
            $defaultConnectionId = $request->input('default_ecommerce_connection_id', $request->input('ecommerce_channel_id'));
            $validCommerceIds = $this->validateCommerceConnections($connectionIds ?? [], $defaultConnectionId, $business);
            $updates['ecommerce_channel_id'] = $defaultConnectionId;
        }
        $knowledgeAssignments = $request->has('knowledge_assignments')
            ? $this->validateKnowledgeAssignments($request->input('knowledge_assignments', []), $business)
            : null;
        $validChannelIds = $request->has('channel_ids')
            ? Channel::whereIn('id', $request->input('channel_ids', []))->where('business_id', $business->id)->pluck('id')->all()
            : $bot->channels()->pluck('channels.id')->all();
        $routingCommerceIds = $validCommerceIds ?? $bot->ecommerceConnections()->pluck('channels.id')->map(fn ($value) => (int) $value)->all();
        $channelStoreRouting = $this->validateChannelStoreRouting(
            $request->input('channel_store_routing', []),
            $validChannelIds,
            $routingCommerceIds,
            $business
        );
        $existingRoutes = DB::table('bot_channels')
            ->where('bot_id', $bot->id)
            ->whereIn('channel_id', $validChannelIds)
            ->pluck('ecommerce_connection_id', 'channel_id')
            ->all();

        DB::transaction(function () use ($bot, $updates, $connectionFieldsProvided, $validCommerceIds, $defaultConnectionId, $request, $validChannelIds, $knowledgeAssignments, $business, $channelStoreRouting, $existingRoutes) {
        $bot->update($updates);
        if ($connectionFieldsProvided) {
            $this->syncCommerceConnections($bot, $validCommerceIds ?? [], $defaultConnectionId);
        }

        if ($request->has('channel_ids')) {
            $primaryIds = array_values(array_intersect(
                array_map('intval', $request->input('primary_channel_ids', [])),
                array_map('intval', $validChannelIds)
            ));

            // Enforce is_primary integrity: only one primary bot per channel
            if (!empty($primaryIds)) {
                \DB::table('bot_channels')
                    ->whereIn('channel_id', $primaryIds)
                    ->whereIn('channel_id', $validChannelIds)
                    ->where('bot_id', '!=', $bot->id)
                    ->update(['is_primary' => false]);
            }

            $syncData = [];
            foreach ($validChannelIds as $chId) {
                $syncData[$chId] = [
                    'is_primary' => in_array($chId, $primaryIds),
                    'ecommerce_connection_id' => array_key_exists($chId, $channelStoreRouting)
                        ? $channelStoreRouting[$chId]
                        : ($existingRoutes[$chId] ?? null),
                ];
            }
            $bot->channels()->sync($syncData);
        }

        foreach ($channelStoreRouting as $channelId => $connectionId) {
            DB::table('bot_channels')
                ->where('bot_id', $bot->id)
                ->where('channel_id', $channelId)
                ->update(['ecommerce_connection_id' => $connectionId]);
        }

        if ($request->has('knowledge_assignments')) {
            BotKnowledgeAssignment::where('bot_id', $bot->id)->delete();
            foreach ($knowledgeAssignments ?? [] as $assign) {
                BotKnowledgeAssignment::create([
                    'bot_id' => $bot->id,
                    'business_knowledge_file_id' => $assign['file_id'],
                    'channel_id' => $assign['channel_id'] ?? null,
                ]);
            }
        }

        });

        return response()->json([
            'message' => 'Bot updated successfully',
            'bot' => $bot->load(['channels:id,type,page_name,page_id', 'knowledgeAssignments']),
        ]);
    }

    public function destroy(Request $request, $id)
    {
        $business = $this->getResolvedBusinessProfile($request, 'write');
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
        $business = $this->getResolvedBusinessProfile($request, 'read');
        $bot = Bot::where('business_profile_id', $business->id)
            ->with(['channels:id,type,page_name,page_id,status', 'ecommerceConnections:id,type,page_name,page_id,status'])
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
        $business = $this->getResolvedBusinessProfile($request, 'write');
        $bot = Bot::where('business_profile_id', $business->id)->findOrFail($id);

        $request->validate([
            'channel_ids' => 'required|array|min:1',
            'channel_ids.*' => 'integer',
            'primary_channel_ids' => 'nullable|array',
            'primary_channel_ids.*' => 'integer',
        ]);

        $validChannelIds = Channel::whereIn('id', $request->channel_ids)
            ->where('business_id', $business->id)
            ->pluck('id')
            ->toArray();

        if (empty($validChannelIds)) {
            return response()->json(['error' => 'No valid channels found'], 422);
        }

        $primaryIds = array_values(array_intersect(
            array_map('intval', $request->input('primary_channel_ids', [])),
            array_map('intval', $validChannelIds)
        ));

        // Enforce is_primary integrity: only one primary bot per channel
        if (!empty($primaryIds)) {
            \DB::table('bot_channels')
                ->whereIn('channel_id', $primaryIds)
                ->whereIn('channel_id', $validChannelIds)
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
        $business = $this->getResolvedBusinessProfile($request, 'write');
        $bot = Bot::where('business_profile_id', $business->id)->findOrFail($id);

        $channel = Channel::where('id', $channelId)
            ->where('business_id', $business->id)
            ->firstOrFail();

        $bot->channels()->detach($channel->id);

        return response()->json(['message' => 'Channel detached successfully']);
    }

    private function validateChannelStoreRouting(array $routing, array $selectedChannelIds, array $commerceIds, BusinessProfile $business): array
    {
        $selectedChannelIds = array_map('intval', $selectedChannelIds);
        $commerceIds = array_map('intval', $commerceIds);
        $validated = [];

        foreach ($routing as $rawChannelId => $connectionId) {
            $channelId = filter_var($rawChannelId, FILTER_VALIDATE_INT);
            if (!$channelId || !in_array((int) $channelId, $selectedChannelIds, true)) {
                throw ValidationException::withMessages([
                    "channel_store_routing.{$rawChannelId}" => 'The route must belong to a channel assigned to this agent.',
                ]);
            }

            $channelExists = Channel::query()->whereKey($channelId)
                ->where('business_id', $business->id)
                ->whereNotIn('type', ['salla', 'shopify', 'woocommerce'])
                ->exists();
            if (!$channelExists) {
                throw ValidationException::withMessages([
                    "channel_store_routing.{$rawChannelId}" => 'The selected channel is not available to this business.',
                ]);
            }

            if ($connectionId !== null) {
                $connectionId = (int) $connectionId;
                if (!in_array($connectionId, $commerceIds, true) || !Channel::query()
                    ->whereKey($connectionId)
                    ->where('business_id', $business->id)
                    ->where('status', 'connected')
                    ->whereIn('type', ['salla', 'shopify', 'woocommerce'])
                    ->exists()) {
                    throw ValidationException::withMessages([
                        "channel_store_routing.{$rawChannelId}" => 'The selected store must be enabled and connected for this agent.',
                    ]);
                }
            }

            $validated[(int) $channelId] = $connectionId === null ? null : (int) $connectionId;
        }

        return $validated;
    }

    private function validateCommerceConnections(array $ids, $defaultId, BusinessProfile $business): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if ($defaultId !== null && !in_array((int) $defaultId, $ids, true)) {
            $ids[] = (int) $defaultId;
        }

        if (!$ids) {
            return [];
        }

        $valid = Channel::query()
            ->whereIn('id', $ids)
            ->where('business_id', $business->id)
            ->whereIn('type', ['salla', 'shopify', 'woocommerce'])
            ->where('status', 'connected')
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        if (count($valid) !== count($ids)) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'ecommerce_connection_ids' => 'One or more commerce connections are not connected to this business.',
            ]);
        }

        return $valid;
    }

    private function syncCommerceConnections(Bot $bot, array $ids, $defaultId): void
    {
        $sync = [];
        foreach ($ids as $id) {
            $sync[$id] = [
                'is_enabled' => true,
                'is_default' => $defaultId !== null && (int) $defaultId === (int) $id,
            ];
        }
        $bot->ecommerceConnections()->sync($sync);
    }

    /** Validate nested knowledge references against the request's business. */
    private function validateKnowledgeAssignments(array $assignments, BusinessProfile $business): array
    {
        $validated = [];
        foreach ($assignments as $index => $assignment) {
            $file = \App\Models\BusinessKnowledgeFile::query()
                ->where('id', $assignment['file_id'])
                ->where('business_profile_id', $business->id)
                ->first();
            if (!$file) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    "knowledge_assignments.{$index}.file_id" => 'The selected knowledge file is not available to this business.',
                ]);
            }

            $channelId = isset($assignment['channel_id']) ? (int) $assignment['channel_id'] : null;
            if ($channelId !== null && !Channel::query()
                ->where('id', $channelId)
                ->where('business_id', $business->id)
                ->exists()) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    "knowledge_assignments.{$index}.channel_id" => 'The selected channel is not available to this business.',
                ]);
            }

            $validated[] = [
                'file_id' => (int) $file->id,
                'channel_id' => $channelId,
            ];
        }

        return $validated;
    }

    // ── BOT-KNOWLEDGE ASSIGNMENT MANAGEMENT ──────────────────────────────────

    /**
     * GET /api/bots/{id}/knowledge — list knowledge files assigned to this bot
     */
    public function knowledge(Request $request, $id)
    {
        $business = $this->getResolvedBusinessProfile($request, 'read');
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
        $business = $this->getResolvedBusinessProfile($request, 'write');
        $bot = Bot::where('business_profile_id', $business->id)->findOrFail($id);

        $request->validate([
            'assignments' => 'required|array|min:1',
            'assignments.*.file_id' => 'required|integer|exists:business_knowledge_files,id',
            'assignments.*.channel_id' => 'nullable|integer|exists:channels,id',
        ]);

        foreach ($request->assignments as $assign) {
            // Verify the file belongs to the user's business
            $file = \App\Models\BusinessKnowledgeFile::where('id', $assign['file_id'])
                ->where('business_profile_id', $business->id)
                ->firstOrFail();

            $channelId = $assign['channel_id'] ?? null;
            if ($channelId !== null) {
                Channel::where('id', $channelId)
                    ->where('business_id', $business->id)
                    ->firstOrFail();
            }

            BotKnowledgeAssignment::create([
                'bot_id' => $bot->id,
                'business_knowledge_file_id' => $file->id,
                'channel_id' => $channelId,
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
        $business = $this->getResolvedBusinessProfile($request, 'write');
        $bot = Bot::where('business_profile_id', $business->id)->findOrFail($id);

        $assignment = BotKnowledgeAssignment::where('bot_id', $bot->id)
            ->where('business_knowledge_file_id', $fileId)
            ->firstOrFail();

        $assignment->delete();

        return response()->json(['message' => 'Knowledge assignment removed successfully']);
    }
}
