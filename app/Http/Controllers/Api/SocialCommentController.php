<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Message;
use App\Models\Bot;
use App\Models\Channel;
use App\Models\SocialCommentEvent;
use App\Models\SocialCommentTestScenario;
use App\Services\ActiveBusiness;
use App\Services\EcommerceChannelResolver;
use App\Services\SocialCommentRuleEvaluator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SocialCommentController extends Controller
{
    /** Evaluate synthetic input only; this endpoint has no outbound send path. */
    public function simulate(Request $request, ActiveBusiness $activeBusiness, SocialCommentRuleEvaluator $evaluator)
    {
        $business = $activeBusiness->resolve($request, permission: 'conversation_read');
        $input = $request->validate([
            'bot_id' => ['required', 'integer'],
            'channel_id' => ['required', 'integer'],
            'comment_text' => ['required', 'string', 'max:5000'],
            'post_id' => ['nullable', 'string', 'max:160'],
            'comment_created_at' => ['nullable', 'date'],
            'is_live_comment' => ['sometimes', 'boolean'],
        ]);

        $bot = Bot::query()->where('business_profile_id', $business->id)->findOrFail((int) $input['bot_id']);
        $channel = Channel::query()->where('business_id', $business->id)
            ->whereIn('type', ['facebook', 'instagram'])
            ->findOrFail((int) $input['channel_id']);
        $assignment = $bot->channels()->where('channels.id', $channel->id)->first()?->pivot;
        if (!$assignment) {
            return response()->json(['message' => 'This social account is not assigned to the selected bot.'], 422);
        }

        $settings = $this->decodeSettings($assignment->comment_settings);
        $evaluation = $evaluator->evaluate(
            $settings,
            $input['comment_text'],
            postId: $input['post_id'] ?? null,
            automationEnabled: (bool) $assignment->comment_automation_enabled,
            publicReplyEnabled: (bool) $assignment->public_comment_reply_enabled,
            privateReplyEnabled: false,
            platform: $channel->type,
            eventType: 'comment_created',
            createdAt: isset($input['comment_created_at']) ? new \DateTimeImmutable($input['comment_created_at']) : now(),
            isLiveComment: (bool) ($input['is_live_comment'] ?? false),
        );

        $connected = $channel->status === 'connected' && trim((string) $channel->access_token) !== '';
        $decision = $evaluation;
        if (!$connected && $evaluation['matched']) {
            $decision['permitted_actions'] = [];
            $decision['action'] = 'none';
            $decision['reason'] = 'account_disconnected';
        }

        $commerceContext = app(EcommerceChannelResolver::class)->resolveForChannel($bot, $channel, (int) $business->id);
        $commerceConnection = $commerceContext['connection'];

        $preview = null;
        $previewSource = null;
        if (($decision['permitted_actions'] ?? []) && in_array('public', $decision['permitted_actions'], true)) {
            $variants = $decision['public_reply_variants'] ?? [];
            if ($variants !== []) {
                $index = hexdec(substr(hash('sha256', $input['comment_text'] . '|' . ($input['post_id'] ?? '')), 0, 7)) % count($variants);
                $preview = $variants[$index];
                $previewSource = 'configured_variant';
            } elseif (!empty($settings['fallback_response'])) {
                $preview = (string) $settings['fallback_response'];
                $previewSource = 'configured_fallback';
            } else {
                $previewSource = 'ai_response_not_generated_in_dry_run';
            }
        }

        return response()->json([
            'dry_run' => true,
            'decision' => $decision,
            'public_reply_preview' => $preview,
            'preview_source' => $previewSource,
            'private_reply_preview' => null,
            'diagnostics' => [
                'channel_connected' => $connected,
                'comment_webhook_status' => data_get($channel->metadata, 'comment_webhook_status', 'unknown'),
                'private_reply_enabled' => false,
                'commerce_context' => $commerceConnection ? [
                    'status' => 'resolved',
                    'resolution_source' => $commerceContext['source'],
                    'connection_id' => $commerceConnection->id,
                    'provider' => $commerceConnection->type,
                    'name' => $commerceConnection->page_name ?: $commerceConnection->page_id,
                ] : ['status' => 'unresolved', 'resolution_source' => $commerceContext['source']],
            ],
        ]);
    }

    public function scenarios(Request $request, ActiveBusiness $activeBusiness)
    {
        $business = $activeBusiness->resolve($request, permission: 'conversation_read');
        $query = SocialCommentTestScenario::query()->where('business_id', $business->id)
            ->with(['bot:id,name,status', 'channel:id,type,page_name,status']);
        if ($request->filled('bot_id')) {
            $request->validate(['bot_id' => ['integer']]);
            $query->where('bot_id', (int) $request->input('bot_id'));
        }

        return response()->json(['data' => $query->orderBy('id')->get()]);
    }

    public function storeScenario(Request $request, ActiveBusiness $activeBusiness)
    {
        $business = $activeBusiness->resolve($request, permission: 'conversation_write');
        $input = $request->validate([
            'bot_id' => ['required', 'integer'],
            'channel_id' => ['required', 'integer'],
            'name' => ['required', 'string', 'max:120'],
            'input.comment_text' => ['required', 'string', 'max:5000'],
            'input.post_id' => ['nullable', 'string', 'max:160'],
            'input.is_live_comment' => ['sometimes', 'boolean'],
            'expected.matched' => ['required', 'boolean'],
            'expected.rule_id' => ['sometimes', 'nullable', 'string', 'max:120'],
            'expected.action' => ['sometimes', 'in:none,public'],
            'expected.reason' => ['sometimes', 'nullable', 'string', 'max:64'],
        ]);

        $bot = Bot::query()->where('business_profile_id', $business->id)->findOrFail((int) $input['bot_id']);
        $channel = Channel::query()->where('business_id', $business->id)
            ->whereIn('type', ['facebook', 'instagram'])->findOrFail((int) $input['channel_id']);
        if (!$bot->channels()->where('channels.id', $channel->id)->exists()) {
            return response()->json(['message' => 'This social account is not assigned to the selected bot.'], 422);
        }

        $scenario = SocialCommentTestScenario::create([
            'business_id' => $business->id,
            'bot_id' => $bot->id,
            'channel_id' => $channel->id,
            'created_by' => $request->user()?->id,
            'name' => trim($input['name']),
            'input' => $input['input'],
            'expected' => $input['expected'],
        ]);

        return response()->json(['data' => $scenario], 201);
    }

    public function deleteScenario(Request $request, ActiveBusiness $activeBusiness, int $scenarioId)
    {
        $business = $activeBusiness->resolve($request, permission: 'conversation_write');
        $scenario = SocialCommentTestScenario::query()->where('business_id', $business->id)->findOrFail($scenarioId);
        $scenario->delete();

        return response()->json(['deleted' => true]);
    }

    public function runScenarios(Request $request, ActiveBusiness $activeBusiness, SocialCommentRuleEvaluator $evaluator)
    {
        $business = $activeBusiness->resolve($request, permission: 'conversation_read');
        $filters = $request->validate([
            'scenario_ids' => ['sometimes', 'array', 'min:1', 'max:100'],
            'scenario_ids.*' => ['integer'],
            'bot_id' => ['sometimes', 'integer'],
        ]);
        $query = SocialCommentTestScenario::query()->where('business_id', $business->id)
            ->with(['bot', 'channel']);
        if (isset($filters['scenario_ids'])) $query->whereIn('id', $filters['scenario_ids']);
        if (isset($filters['bot_id'])) $query->where('bot_id', $filters['bot_id']);
        $scenarios = $query->orderBy('id')->get();
        if (isset($filters['scenario_ids']) && $scenarios->count() !== count(array_unique($filters['scenario_ids']))) {
            return response()->json(['message' => 'One or more test scenarios are unavailable.'], 404);
        }

        $results = $scenarios->map(function (SocialCommentTestScenario $scenario) use ($evaluator): array {
            $bot = $scenario->bot;
            $channel = $scenario->channel;
            $assignment = $bot && $channel
                ? $bot->channels()->where('channels.id', $channel->id)->first()?->pivot
                : null;
            $diagnostic = null;
            $decision = null;
            $settings = $this->decodeSettings($assignment?->comment_settings);
            if (!$bot || !$channel || !$assignment) {
                $diagnostic = 'account_not_assigned';
                $decision = ['matched' => false, 'reason' => $diagnostic, 'rule' => null, 'action' => 'none'];
            } else {
                $decision = $evaluator->evaluate(
                    $settings,
                    (string) ($scenario->input['comment_text'] ?? ''),
                    postId: $scenario->input['post_id'] ?? null,
                    automationEnabled: (bool) $assignment->comment_automation_enabled,
                    publicReplyEnabled: (bool) $assignment->public_comment_reply_enabled,
                    privateReplyEnabled: false,
                    platform: $channel->type,
                    eventType: 'comment_created',
                    createdAt: now(),
                    isLiveComment: (bool) ($scenario->input['is_live_comment'] ?? false),
                );
                if ($channel->status !== 'connected' || trim((string) $channel->access_token) === '') {
                    $diagnostic = 'account_disconnected';
                    if ($decision['matched']) {
                        $decision['permitted_actions'] = [];
                        $decision['action'] = 'none';
                        $decision['reason'] = $diagnostic;
                    }
                }
            }

            $actual = [
                'matched' => (bool) ($decision['matched'] ?? false),
                'rule_id' => data_get($decision, 'rule.id'),
                'action' => $decision['action'] ?? 'none',
                'reason' => $decision['reason'] ?? null,
            ];
            $expected = (array) $scenario->expected;
            $checks = [];
            foreach ($expected as $key => $value) $checks[$key] = $actual[$key] === $value;

            return [
                'scenario_id' => $scenario->id,
                'name' => $scenario->name,
                'passed' => !in_array(false, $checks, true),
                'expected' => $expected,
                'actual' => $actual,
                'checks' => $checks,
                'decision' => $decision,
                'diagnostic' => $diagnostic,
                'configuration_hash' => hash('sha256', json_encode($settings, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}'),
            ];
        })->values();

        return response()->json([
            'dry_run' => true,
            'summary' => ['total' => $results->count(), 'passed' => $results->where('passed', true)->count(), 'failed' => $results->where('passed', false)->count()],
            'results' => $results,
        ]);
    }

    public function index(Request $request, ActiveBusiness $activeBusiness)
    {
        $business = $activeBusiness->resolve($request, permission: 'conversation_read');
        $filters = $request->validate([
            'platform' => ['sometimes', 'nullable', 'in:facebook,instagram'],
            'channel_id' => ['sometimes', 'nullable', 'integer'],
            'status' => ['sometimes', 'nullable', 'in:all,needs_attention,replied,received,processing,processed,skipped,failed,retryable'],
            'search' => ['sometimes', 'nullable', 'string', 'max:150'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $statusCounts = SocialCommentEvent::query()
            ->where('business_id', $business->id)
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');
        $total = (int) $statusCounts->sum();
        $replied = (int) ($statusCounts['processed'] ?? 0);

        $query = SocialCommentEvent::query()
            ->where('business_id', $business->id)
            ->with(['channel:id,type,page_name,page_id', 'bot:id,name']);

        if (!empty($filters['platform'])) {
            $query->where('platform', $filters['platform']);
        }
        if (!empty($filters['channel_id'])) {
            $channelOwned = $business->channels()->whereKey((int) $filters['channel_id'])->exists();
            if (!$channelOwned) {
                return response()->json(['data' => [], 'current_page' => 1, 'last_page' => 1, 'total' => 0]);
            }
            $query->where('channel_id', (int) $filters['channel_id']);
        }
        if (!empty($filters['status'])) {
            if ($filters['status'] === 'replied') {
                $query->where('status', 'processed');
            } elseif ($filters['status'] === 'needs_attention') {
                $query->whereIn('status', ['received', 'processing', 'scheduled', 'failed', 'retryable']);
            } elseif ($filters['status'] !== 'all') {
                $query->where('status', $filters['status']);
            }
        }
        if (!empty($filters['search'])) {
            $term = addcslashes(trim($filters['search']), '%_\\');
            $query->where(function ($comments) use ($term): void {
                $comments->where('comment_text', 'like', "%{$term}%")
                    ->orWhere('commenter_id', 'like', "%{$term}%")
                    ->orWhere('post_id', 'like', "%{$term}%");
            });
        }

        $events = $query->orderByRaw('COALESCE(platform_created_at, created_at) DESC')
            ->paginate((int) ($filters['per_page'] ?? 30));
        $eventIds = $events->getCollection()->modelKeys();

        if ($eventIds) {
            $replies = Message::query()
                ->whereIn('metadata->social_comment_event_id', $eventIds)
                ->where('source', 'comment_public_reply')
                ->orderBy('created_at')
                ->get()
                ->groupBy(fn (Message $message) => (string) data_get($message->metadata, 'social_comment_event_id'));

            $events->getCollection()->transform(function (SocialCommentEvent $event) use ($replies): SocialCommentEvent {
                $reply = $replies->get((string) $event->id, collect())->last();
                $trace = (array) data_get($event->payload, 'decision_trace', []);
                // Expose operational codes, never internal exception paths or raw exception text.
                $event->setAttribute('failure_reason', $event->failure_code);
                $event->setAttribute('decision_trace', $trace);
                $event->setAttribute('is_hidden', (bool) data_get($event->payload, 'moderation.hidden', false));
                $event->setAttribute('reply', $reply ? [
                    'id' => $reply->id,
                    'content' => $reply->content,
                    'source' => $reply->source,
                    'is_ai' => (bool) $reply->is_ai,
                    'created_at' => $reply->created_at,
                ] : null);
                return $event;
            });
        }

        return response()->json([
            'data' => $events->items(),
            'current_page' => $events->currentPage(),
            'last_page' => $events->lastPage(),
            'per_page' => $events->perPage(),
            'total' => $events->total(),
            'stats' => [
                'total' => $total,
                'replied' => $replied,
                'skipped' => (int) ($statusCounts['skipped'] ?? 0),
                'cancelled' => (int) ($statusCounts['cancelled'] ?? 0),
                'failed' => (int) ($statusCounts['failed'] ?? 0),
                'retryable' => (int) ($statusCounts['retryable'] ?? 0),
                'pending' => (int) ($statusCounts['received'] ?? 0) + (int) ($statusCounts['processing'] ?? 0) + (int) ($statusCounts['scheduled'] ?? 0),
                'needs_attention' => (int) ($statusCounts['received'] ?? 0) + (int) ($statusCounts['processing'] ?? 0)
                    + (int) ($statusCounts['scheduled'] ?? 0) + (int) ($statusCounts['failed'] ?? 0) + (int) ($statusCounts['retryable'] ?? 0),
            ],
        ]);
    }

    public function reply(Request $request, ActiveBusiness $activeBusiness, int $eventId)
    {
        $business = $activeBusiness->resolve($request, permission: 'conversation_write');
        $input = $request->validate(['message' => ['required', 'string', 'max:500']]);
        $event = SocialCommentEvent::query()->where('business_id', $business->id)->findOrFail($eventId);
        $channel = Channel::query()->where('business_id', $business->id)->findOrFail($event->channel_id);
        $this->assertEventChannel($event, $channel);

        $inbound = Message::query()->where('source', 'social_comment')
            ->where('metadata->social_comment_event_id', $event->id)->oldest('id')->first();
        if (!$inbound) {
            return response()->json(['message' => 'This comment is still being prepared. Refresh and try again shortly.'], 409);
        }

        $result = app(\App\Services\MetaCommentAutomationService::class)->publishManualPublic($event, $channel, trim($input['message']));
        if (empty($result['success'])) {
            return $this->metaActionFailure($result);
        }

        DB::transaction(function () use ($event, $inbound, $input, $result): void {
            Message::create([
                'conversation_id' => $inbound->conversation_id,
                'channel_id' => $event->channel_id,
                'platform_message_id' => $result['reply_id'] ?? null,
                'content' => trim($input['message']),
                'direction' => 'outbound', 'type' => 'text', 'status' => 'sent',
                'send_status' => 'sent', 'delivery_status' => 'sent', 'source' => 'comment_public_reply',
                'is_ai' => false,
                'metadata' => ['social_comment_event_id' => $event->id, 'public_reply_id' => $result['reply_id'] ?? null, 'manual' => true],
            ]);
            $trace = (array) data_get($event->payload, 'decision_trace', []);
            $manualReplies = (array) data_get($event->payload, 'manual_public_replies', []);
            $manualReplies[] = ['reply_id' => $result['reply_id'] ?? null, 'sent_at' => now()->toIso8601String()];
            $event->forceFill([
                'status' => 'processed', 'failure_code' => null, 'processed_at' => now(),
                'payload' => array_merge((array) $event->payload, [
                    'manual_public_replies' => $manualReplies,
                    'decision_trace' => array_merge($trace, ['manual_reply_sent_at' => now()->toIso8601String(), 'final_status' => 'processed']),
                ]),
            ])->save();
        });

        return response()->json(['success' => true, 'reply_id' => $result['reply_id'] ?? null]);
    }

    public function editReply(Request $request, ActiveBusiness $activeBusiness, int $eventId, int $messageId)
    {
        $business = $activeBusiness->resolve($request, permission: 'conversation_write');
        $input = $request->validate(['message' => ['required', 'string', 'max:500']]);
        $event = SocialCommentEvent::query()->where('business_id', $business->id)->findOrFail($eventId);
        $channel = Channel::query()->where('business_id', $business->id)->findOrFail($event->channel_id);
        $this->assertEventChannel($event, $channel);
        if ($event->platform !== 'facebook') {
            return response()->json(['message' => 'Meta does not expose comment-reply editing for Instagram through this API. You can send a corrected follow-up reply instead.'], 422);
        }

        $message = Message::query()->where('id', $messageId)->where('source', 'comment_public_reply')
            ->where('is_ai', true)->where('metadata->social_comment_event_id', $event->id)->firstOrFail();
        $replyId = data_get($message->metadata, 'public_reply_id') ?: $message->platform_message_id;
        if (!$replyId) {
            return response()->json(['message' => 'The platform reply ID is unavailable, so this reply cannot be edited.'], 409);
        }

        $result = app(\App\Services\MetaCommentAutomationService::class)->editFacebookReply($event, $channel, (string) $replyId, trim($input['message']));
        if (empty($result['success'])) {
            return $this->metaActionFailure($result);
        }

        $message->forceFill(['content' => trim($input['message']), 'metadata' => array_merge((array) $message->metadata, ['edited_at' => now()->toIso8601String()])])->save();

        return response()->json(['success' => true]);
    }

    public function setVisibility(Request $request, ActiveBusiness $activeBusiness, int $eventId)
    {
        $business = $activeBusiness->resolve($request, permission: 'conversation_write');
        $input = $request->validate(['hidden' => ['required', 'boolean']]);
        $event = SocialCommentEvent::query()->where('business_id', $business->id)->findOrFail($eventId);
        $channel = Channel::query()->where('business_id', $business->id)->findOrFail($event->channel_id);
        $this->assertEventChannel($event, $channel);

        $result = app(\App\Services\MetaCommentAutomationService::class)->setCommentHidden($event, $channel, (bool) $input['hidden']);
        if (empty($result['success'])) {
            return $this->metaActionFailure($result);
        }

        $payload = (array) $event->payload;
        $moderation = (array) ($payload['moderation'] ?? []);
        $moderation['hidden'] = (bool) $input['hidden'];
        $moderation['updated_at'] = now()->toIso8601String();
        $event->forceFill(['payload' => array_merge($payload, ['moderation' => $moderation])])->save();

        return response()->json(['success' => true, 'hidden' => (bool) $input['hidden']]);
    }

    private function assertEventChannel(SocialCommentEvent $event, Channel $channel): void
    {
        $expectedAccountId = $event->platform === 'facebook' ? $channel->page_id : $channel->instagram_account_id;
        abort_unless(
            in_array($event->platform, ['facebook', 'instagram'], true)
            && $channel->type === $event->platform
            && $event->account_id === $expectedAccountId,
            422,
            'The comment and connected social account do not match.'
        );
    }

    private function metaActionFailure(array $result)
    {
        if (($result['error_code'] ?? null) === 'platform_edit_unsupported') {
            return response()->json(['message' => 'This action is not supported for this platform.'], 422);
        }
        if (($result['error_code'] ?? null) === 'account_disconnected') {
            return response()->json(['message' => 'Reconnect this social account, then try again.'], 422);
        }
        $code = (string) ($result['error_code'] ?? 'unknown');
        return response()->json([
            'message' => 'Meta rejected this comment action. Check that the connected account has comment-management access and that the app has the required permission.',
            'provider_error_code' => $code,
        ], !empty($result['retryable']) ? 503 : 422);
    }

    private function decodeSettings(mixed $value): array
    {
        if (is_array($value)) return $value;
        if (!is_string($value) || $value === '') return [];
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : [];
    }
}
