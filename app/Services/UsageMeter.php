<?php

namespace App\Services;

use App\Models\BusinessProfile;
use App\Models\Channel;
use App\Models\Message;
use App\Models\Package;
use App\Models\Subscription;
use Illuminate\Database\Eloquent\Builder;

class UsageMeter
{
    public const AI_REPLIES = 'ai_replies';
    public const OUTBOUND_MESSAGES = 'outbound_messages';
    public const CHANNELS = 'channels';

    /**
     * Return the limits and current period usage for the selected business.
     * Subscription ownership is resolved from the business, never from the
     * acting teammate's personal subscription.
     */
    public function snapshot(BusinessProfile $business): array
    {
        $subscription = $this->subscriptionFor($business);
        $package = $subscription?->package ?? Package::query()->where('name', 'Free')->first();
        $periodStart = now()->startOfMonth();
        $periodEnd = now()->endOfMonth();

        $metrics = [];
        foreach ([self::AI_REPLIES, self::OUTBOUND_MESSAGES, self::CHANNELS] as $metric) {
            $limit = $this->limitFor($package, $metric);
            $used = $this->usedFor($business, $metric, $periodStart);
            $unlimited = $limit < 0;
            $metrics[$metric] = [
                'used' => $used,
                'limit' => $unlimited ? -1 : $limit,
                'remaining' => $unlimited ? null : max(0, $limit - $used),
                'unlimited' => $unlimited,
                'percentage' => $unlimited || $limit === 0 ? null : round(($used / $limit) * 100, 2),
                'allowed' => $unlimited || $used < $limit,
            ];
        }

        return [
            'business_id' => $business->id,
            'period' => [
                'starts_at' => $periodStart->toISOString(),
                'ends_at' => $periodEnd->toISOString(),
            ],
            'plan' => [
                'id' => $package?->id,
                'name' => $package?->name ?? 'Unavailable',
                'subscription_id' => $subscription?->id,
                'subscription_status' => $subscription?->status,
            ],
            'usage' => $metrics,
        ];
    }

    /**
     * Determine whether one more unit can be consumed. An outbound message
     * that has already been created is excluded from its own pre-send gate.
     */
    public function canUse(
        BusinessProfile $business,
        string $metric,
        ?int $excludeMessageId = null,
        ?int $excludeEmailRecipientId = null,
    ): array
    {
        if (!in_array($metric, [self::AI_REPLIES, self::OUTBOUND_MESSAGES, self::CHANNELS], true)) {
            throw new \InvalidArgumentException('Unknown usage metric.');
        }

        $package = $this->subscriptionFor($business)?->package ?? Package::query()->where('name', 'Free')->first();
        $limit = $this->limitFor($package, $metric);
        $used = $this->usedFor($business, $metric, now()->startOfMonth(), $excludeMessageId, $excludeEmailRecipientId);
        $unlimited = $limit < 0;

        return [
            'allowed' => $unlimited || $used < $limit,
            'metric' => $metric,
            'used' => $used,
            'limit' => $unlimited ? -1 : $limit,
            'unlimited' => $unlimited,
        ];
    }

    /** Mark an already-created outbound attempt blocked before a provider call. */
    public function blockMessageIfOverLimit(BusinessProfile $business, Message $message): bool
    {
        if ($message->is_ai) {
            $ai = $this->canUse($business, self::AI_REPLIES, (int) $message->id);
            if (!$ai['allowed']) {
                $this->markBlocked($message, 'The business plan limit for monthly AI replies has been reached.');
                return true;
            }
        }

        $outbound = $this->canUse($business, self::OUTBOUND_MESSAGES, (int) $message->id);
        if (!$outbound['allowed']) {
            $this->markBlocked($message, 'The business plan limit for monthly outbound messages has been reached.');
            return true;
        }

        return false;
    }

    public function subscriptionFor(BusinessProfile $business): ?Subscription
    {
        $subscription = Subscription::query()
            ->with('package')
            ->where('business_id', $business->id)
            ->whereIn('status', ['active', 'trial'])
            ->where(fn (Builder $query) => $query->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->where(fn (Builder $query) => $query->whereNull('ends_at')->orWhere('ends_at', '>', now()))
            ->latest('starts_at')
            ->latest('id')
            ->first();

        if ($subscription) {
            return $subscription;
        }

        // Compatibility for legacy subscriptions that could not be mapped
        // during migration. Only the owner's primary business may use one.
        if ((int) ($business->user?->business_id ?? 0) !== (int) $business->id) {
            return null;
        }

        return Subscription::query()
            ->with('package')
            ->where('user_id', $business->user_id)
            ->whereNull('business_id')
            ->whereIn('status', ['active', 'trial'])
            ->where(fn (Builder $query) => $query->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->where(fn (Builder $query) => $query->whereNull('ends_at')->orWhere('ends_at', '>', now()))
            ->latest('starts_at')
            ->latest('id')
            ->first();
    }

    private function limitFor(?Package $package, string $metric): int
    {
        $attribute = match ($metric) {
            self::AI_REPLIES => 'ai_replies_limit',
            self::OUTBOUND_MESSAGES => 'outbound_messages_limit',
            self::CHANNELS => 'channels_limit',
        };

        return (int) ($package?->{$attribute} ?? -1);
    }

    private function usedFor(
        BusinessProfile $business,
        string $metric,
        $periodStart,
        ?int $excludeMessageId = null,
        ?int $excludeEmailRecipientId = null,
    ): int
    {
        if ($metric === self::CHANNELS) {
            // Commerce stores are connections, not messaging channels: they do not use the channel allowance.
            // Disconnected channels do not count either.
            return Channel::query()
                ->where('business_id', $business->id)
                ->where('status', 'connected')
                ->whereNotIn('type', ['salla', 'shopify', 'woocommerce'])
                ->count();
        }

        $messageCount = $this->billableOutboundMessages($business, $periodStart, $excludeMessageId)
            ->when($metric === self::AI_REPLIES, fn (Builder $query) => $query->where('messages.is_ai', true))
            ->count();

        if ($metric === self::AI_REPLIES) {
            return $messageCount;
        }

        // Email campaigns do not create inbox Message rows, so their accepted
        // and currently claimed recipients are included from their own ledger.
        $emailCampaignCount = \App\Models\EmailCampaignRecipient::query()
            ->join('email_campaigns', 'email_campaigns.id', '=', 'email_campaign_recipients.email_campaign_id')
            ->where('email_campaigns.business_id', $business->id)
            ->whereIn('email_campaign_recipients.status', ['sending', 'sent', 'opened'])
            ->where(function (Builder $query) use ($periodStart): void {
                $query->whereBetween('email_campaign_recipients.sent_at', [$periodStart, now()])
                    ->orWhere(function (Builder $pending) use ($periodStart): void {
                        $pending->whereNull('email_campaign_recipients.sent_at')
                            ->where('email_campaign_recipients.status', 'sending')
                            ->whereBetween('email_campaign_recipients.updated_at', [$periodStart, now()]);
                    });
            })
            ->when($excludeEmailRecipientId, fn (Builder $query) => $query->where('email_campaign_recipients.id', '!=', $excludeEmailRecipientId))
            ->count();

        return $messageCount + $emailCampaignCount;
    }

    private function billableOutboundMessages(BusinessProfile $business, $periodStart, ?int $excludeMessageId): Builder
    {
        return Message::query()
            ->join('conversations', 'conversations.id', '=', 'messages.conversation_id')
            ->where('conversations.business_id', $business->id)
            ->where('messages.direction', 'outbound')
            ->where('messages.created_at', '>=', $periodStart)
            ->where('messages.created_at', '<=', now())
            ->where(function (Builder $query): void {
                $query->whereIn('messages.send_status', ['queued', 'pending', 'sent', 'delivered', 'read'])
                    ->orWhere(function (Builder $legacy): void {
                        $legacy->whereNull('messages.send_status')
                            ->whereNotIn('messages.status', ['failed', 'blocked']);
                    });
            })
            ->when($excludeMessageId, fn (Builder $query) => $query->where('messages.id', '!=', $excludeMessageId));
    }

    private function markBlocked(Message $message, string $reason): void
    {
        $message->update([
            'status' => 'blocked',
            'send_status' => 'blocked',
            'error_details' => $reason,
        ]);
    }
}
