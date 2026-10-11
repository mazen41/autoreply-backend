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

        return $this->check((int) $conversation->business_id, $contact, $consentChannel, $scope);
    }

    public function check(BusinessProfile|int $business, string $contact, string $channel, string $scope = 'marketing'): ConsentDecision
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
            return $consent === 'opted_in'
                ? new ConsentDecision(true, 'opted_in')
                : new ConsentDecision(false, $consent === 'opted_out' ? 'opted_out' : 'no_consent');
        }

        return $consent === 'opted_out'
            ? new ConsentDecision(false, 'opted_out')
            : new ConsentDecision(true, 'service_policy');
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
