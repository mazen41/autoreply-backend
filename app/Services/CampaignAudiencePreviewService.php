<?php

namespace App\Services;

use App\Models\Channel;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\WhatsAppCloudTemplate;
use Illuminate\Support\Carbon;

class CampaignAudiencePreviewService
{
    public function __construct(private ConsentGate $consentGate) {}

    /**
     * Estimate which conversations could receive a campaign on the selected
     * account. Delivery jobs repeat every check at send time because consent,
     * account state, and Meta's messaging window can change after preview.
     *
     * @return array<string, mixed>
     */
    public function preview(Channel $channel, array $filters = [], mixed $templateId = null): array
    {
        $query = Conversation::query()
            ->where('business_id', $channel->business_id)
            ->where('channel_id', $channel->id);

        if (!empty($filters['tags'])) {
            $query->whereHas('tags', fn ($tagQuery) => $tagQuery->whereIn('tag', $filters['tags']));
        }

        if (!empty($filters['last_activity_days'])) {
            $query->where('last_message_at', '>=', now()->subDays((int) $filters['last_activity_days']));
        }

        $counts = [
            'no_consent' => 0,
            'opted_out' => 0,
            'suppressed' => 0,
            'invalid_contact_context' => 0,
            'messaging_window_expired' => 0,
            'channel_not_connected' => 0,
        ];
        $total = 0;
        $eligible = 0;
        $channelConnected = $channel->status === 'connected';
        $isMeta = in_array(strtolower((string) $channel->type), ['facebook', 'instagram'], true);

        $query->with('channel')->chunkById(500, function ($conversations) use (&$counts, &$total, &$eligible, $channelConnected, $isMeta): void {
            foreach ($conversations as $conversation) {
                $total++;
                $reason = null;

                if (!$channelConnected) {
                    $reason = 'channel_not_connected';
                } else {
                    $consent = $this->consentGate->checkConversation($conversation, 'marketing');
                    if (!$consent->allowed) {
                        $reason = in_array($consent->reason, ['no_consent', 'opted_out', 'suppressed'], true)
                            ? $consent->reason
                            : 'invalid_contact_context';
                    } elseif ($isMeta && !$this->insideMetaMessagingWindow($conversation->id)) {
                        $reason = 'messaging_window_expired';
                    }
                }

                if ($reason === null) {
                    $eligible++;
                } else {
                    $counts[$reason]++;
                }
            }
        });

        $excluded = array_filter($counts, static fn (int $count): bool => $count > 0);
        $configurationReason = null;
        if (!$channelConnected) {
            $configurationReason = 'The selected account is not connected.';
        } elseif ($channel->type === 'whatsapp') {
            if (($channel->metadata['whatsapp_provider'] ?? null) !== 'cloud') {
                $configurationReason = 'Campaign sends require an official WhatsApp Cloud API account.';
            } elseif (!$templateId || !WhatsAppCloudTemplate::query()
                ->where('id', $templateId)
                ->where('business_id', $channel->business_id)
                ->where('channel_id', $channel->id)
                ->where('status', 'APPROVED')
                ->exists()) {
                $configurationReason = 'Select an approved WhatsApp Cloud API template for this account.';
            }
        }

        return [
            'sender' => [
                'channel_id' => $channel->id,
                'channel_type' => $channel->type,
                'account_name' => $channel->page_name ?: $channel->page_id,
                'connected' => $channelConnected,
            ],
            'total_matched' => $total,
            'eligible' => $eligible,
            'excluded' => $counts,
            'excluded_reasons' => $excluded,
            'campaign_ready' => $configurationReason === null,
            'configuration_reason' => $configurationReason,
            'rechecked_at_delivery' => true,
        ];
    }

    private function insideMetaMessagingWindow(int $conversationId): bool
    {
        $lastInboundAt = Message::query()
            ->where('conversation_id', $conversationId)
            ->where('direction', 'inbound')
            ->max('created_at');

        return $lastInboundAt !== null
            && Carbon::parse($lastInboundAt)->greaterThan(now()->subHours(24));
    }
}
