<?php

namespace App\Services;

use App\Models\BusinessProfile;
use App\Models\ContactConsent;
use App\Models\Suppression;

class ConsentGate
{
    public function checkConversation(\App\Models\Conversation $conversation, string $scope = 'marketing'): ConsentDecision
    {
        $channel = $conversation->channel;
        if (!$channel) {
            return new ConsentDecision(false, 'missing_channel');
        }

        $channelType = strtolower((string) $channel->type);
        $consentChannel = $channelType === 'gmail' ? 'email' : $channelType;
        $contact = $channelType === 'gmail'
            ? (string) ($conversation->sender_email ?? '')
            : (string) ($conversation->sender_id ?? '');

        return $this->check((int) $conversation->business_id, $contact, $consentChannel, $scope, $conversation);
    }

    public function check(BusinessProfile|int $business, string $contact, string $channel, string $scope = 'marketing', ?\App\Models\Conversation $conversation = null): ConsentDecision
    {
        $businessId = $business instanceof BusinessProfile ? $business->id : $business;
        $channel = strtolower(trim($channel));
        $identifier = $this->normalizeIdentifier($contact, $channel);

        if (!$businessId || $identifier === '' || !in_array($scope, ['marketing', 'service'], true)) {
            return new ConsentDecision(false, 'invalid_contact_context');
        }

        $suppressed = Suppression::where('business_id', $businessId)
            ->where('channel', $channel)
            ->where('contact_identifier', $identifier)
            ->exists();
        if ($suppressed) {
            return new ConsentDecision(false, 'suppressed');
        }

        $consent = ContactConsent::where('business_id', $businessId)
            ->where('channel', $channel)
            ->where('contact_identifier', $identifier)
            ->where('scope', $scope)
            ->value('status');

        if ($scope === 'marketing') {
            if ($consent === 'opted_in') {
                return new ConsentDecision(true, 'opted_in');
            }

            if ($consent === 'opted_out') {
                return new ConsentDecision(false, 'opted_out');
            }

            // Automatic opt-in for open conversations active within 24 hours
            if (config('consent.auto_opt_in_active_conversations', true)) {
                if (!$conversation) {
                    $conversation = \App\Models\Conversation::where('business_id', $businessId)
                        ->where(function ($q) use ($identifier, $contact) {
                            $q->where('sender_id', $identifier)
                              ->orWhere('sender_id', $contact)
                              ->orWhere('sender_email', $identifier);
                        })
                        ->latest('last_message_at')
                        ->first();
                }

                if ($this->isConversationActiveWithin24Hours($conversation)) {
                    ContactConsent::updateOrCreate(
                        [
                            'business_id' => $businessId,
                            'channel' => $channel,
                            'contact_identifier' => $identifier,
                            'scope' => 'marketing',
                        ],
                        [
                            'status' => 'opted_in',
                            'source' => 'active_24h_window',
                            'captured_at' => now(),
                            'evidence' => 'Auto-opted from active conversation within 24h window',
                        ]
                    );

                    return new ConsentDecision(true, 'active_conversation');
                }
            }

            return new ConsentDecision(false, 'no_consent');
        }

        return $consent === 'opted_out'
            ? new ConsentDecision(false, 'opted_out')
            : new ConsentDecision(true, 'service_policy');
    }

    public function isConversationActiveWithin24Hours(?\App\Models\Conversation $conversation): bool
    {
        if (!$conversation) {
            return false;
        }

        $cutoff = now()->subHours(24);

        if ($conversation->last_message_at) {
            $lastAt = $conversation->last_message_at instanceof \Carbon\CarbonInterface
                ? $conversation->last_message_at
                : \Carbon\Carbon::parse($conversation->last_message_at);

            if ($lastAt->greaterThanOrEqualTo($cutoff)) {
                return true;
            }
        }

        return \App\Models\Message::where('conversation_id', $conversation->id)
            ->where('created_at', '>=', $cutoff)
            ->exists();
    }

    public function normalizeIdentifier(string $contact, string $channel): string
    {
        $contact = trim($contact);
        if ($channel === 'email' || filter_var($contact, FILTER_VALIDATE_EMAIL)) {
            return mb_strtolower($contact);
        }
        if (in_array($channel, ['whatsapp', 'phone', 'sms'], true)) {
            $contact = preg_replace('/[^0-9+]/', '', $contact) ?? '';
            if (str_starts_with($contact, '00')) {
                $contact = '+' . substr($contact, 2);
            }
            return $contact;
        }

        return $contact;
    }
}
