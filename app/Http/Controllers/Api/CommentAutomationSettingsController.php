<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Bot;
use App\Models\Channel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class CommentAutomationSettingsController extends Controller
{
    public function show(Request $request, int $botId)
    {
        $business = app(\App\Services\ActiveBusiness::class)->resolve($request, $request->user(), 'read');
        $bot = Bot::query()->where('business_profile_id', $business->id)->with(['channels' => fn ($query) => $query->whereIn('type', ['facebook', 'instagram'])])->findOrFail($botId);

        return response()->json(['channels' => $bot->channels->map(fn (Channel $channel) => [
            'id' => $channel->id,
            'type' => $channel->type,
            'name' => $channel->page_name,
            'status' => $channel->status,
            'assigned' => true,
            'enabled' => (bool) $channel->pivot->comment_automation_enabled,
            'public_reply_enabled' => (bool) $channel->pivot->public_comment_reply_enabled,
            // Comment automation is public-only. Keep the legacy field in the
            // response for older clients, but never report it as enabled.
            'private_reply_enabled' => false,
            'settings' => $this->decodeSettings($channel->pivot->comment_settings),
        ])->values()]);
    }

    public function update(Request $request, int $botId)
    {
        $business = app(\App\Services\ActiveBusiness::class)->resolve($request, $request->user(), 'write');
        $bot = Bot::query()->where('business_profile_id', $business->id)->findOrFail($botId);
        $request->validate([
            'channels' => 'required|array|min:1',
            'channels.*.channel_id' => 'required|integer',
            'channels.*.enabled' => 'required|boolean',
            'channels.*.public_reply_enabled' => 'required|boolean',
            'channels.*.private_reply_enabled' => 'required|boolean',
            'channels.*.settings' => 'nullable|array',
            'channels.*.settings.public_information' => 'nullable|array|max:7',
            'channels.*.settings.public_information.*' => ['string', Rule::in(['general_business_info', 'product_names', 'product_descriptions', 'product_prices', 'discounts', 'availability', 'product_links'])],
            'channels.*.settings.fallback_response' => 'nullable|string|max:300',
            'channels.*.settings.trigger_keywords' => 'nullable|array|max:30',
            'channels.*.settings.trigger_keywords.*' => 'string|max:80',
            'channels.*.settings.excluded_keywords' => 'nullable|array|max:30',
            'channels.*.settings.excluded_keywords.*' => 'string|max:80',
            'channels.*.settings.rules' => 'nullable|array|max:30',
            'channels.*.settings.rules.*.id' => 'required|string|max:120',
            'channels.*.settings.rules.*.name' => 'required|string|max:120',
            'channels.*.settings.rules.*.keywords' => 'required|array|min:1|max:30',
            'channels.*.settings.rules.*.keywords.*' => 'required|string|min:1|max:80',
            'channels.*.settings.rules.*.excluded_keywords' => 'nullable|array|max:30',
            'channels.*.settings.rules.*.excluded_keywords.*' => 'required|string|min:1|max:80',
            'channels.*.settings.rules.*.match' => 'required|in:contains,equals',
            'channels.*.settings.rules.*.action' => 'required|in:public,dm,both',
            'channels.*.settings.rules.*.public_reply_variants' => 'nullable|array|max:10',
            'channels.*.settings.rules.*.public_reply_variants.*' => 'required|string|min:1|max:500',
            'channels.*.settings.rules.*.dm_message' => 'nullable|string|max:2000',
            'channels.*.settings.rules.*.delay_minutes' => 'required|integer|min:0|max:10080',
            'channels.*.settings.rules.*.post_ids' => 'nullable|array|max:100',
            'channels.*.settings.rules.*.post_ids.*' => 'required|string|min:1|max:160',
            'channels.*.settings.rules.*.enabled' => 'required|boolean',
        ]);

        foreach ($request->input('channels', []) as $channelIndex => $channelInput) {
            $rules = data_get($channelInput, 'settings.rules', []);
            $ruleIds = array_map('strval', array_column($rules, 'id'));
            if (count($ruleIds) !== count(array_unique($ruleIds))) {
                return response()->json(['message' => "Rule IDs must be unique for channel {$channelIndex}."], 422);
            }

            foreach ($rules as $ruleIndex => $rule) {
                foreach (['keywords', 'excluded_keywords', 'post_ids'] as $listKey) {
                    $values = array_map(fn ($value) => mb_strtolower(trim((string) $value)), (array) ($rule[$listKey] ?? []));
                    if (count($values) !== count(array_unique($values))) {
                        return response()->json(['message' => "Rule {$ruleIndex} contains duplicate {$listKey}."], 422);
                    }
                }
                if (($rule['action'] ?? null) !== 'dm' && empty($rule['public_reply_variants'])) {
                    return response()->json(['message' => "Rule {$ruleIndex} requires at least one public reply variant."], 422);
                }
                if (in_array($rule['action'] ?? null, ['dm', 'both'], true) && trim((string) ($rule['dm_message'] ?? '')) === '') {
                    return response()->json(['message' => "Rule {$ruleIndex} requires a private reply message."], 422);
                }
            }
        }

        $items = collect($request->input('channels'));
        $ids = $items->pluck('channel_id')->map(fn ($id) => (int) $id)->unique()->values();
        if ($ids->count() !== $items->count()) {
            return response()->json(['message' => 'Duplicate channel assignments are not allowed.'], 422);
        }

        $validIds = $bot->channels()->where('channels.business_id', $business->id)
            ->whereIn('channels.type', ['facebook', 'instagram'])->whereIn('channels.id', $ids)->pluck('channels.id');
        if ($validIds->count() !== $ids->count()) {
            return response()->json(['message' => 'One or more social accounts are not assigned to this bot.'], 422);
        }

        DB::transaction(function () use ($bot, $items): void {
            foreach ($items as $item) {
                $settings = $item['settings'] ?? [];
                if (array_key_exists('rules', $settings)) {
                    $settings['schema_version'] = 1;
                }
                $bot->channels()->updateExistingPivot((int) $item['channel_id'], [
                    'comment_automation_enabled' => (bool) $item['enabled'],
                    'public_comment_reply_enabled' => (bool) $item['public_reply_enabled'],
                    'private_comment_reply_enabled' => false,
                    'comment_settings' => json_encode($settings, JSON_THROW_ON_ERROR),
                ]);
            }
        });

        return $this->show($request, $botId);
    }

    private function decodeSettings(mixed $value): array
    {
        if (is_array($value)) return $value;
        if (!is_string($value) || $value === '') return [];
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : [];
    }
}
