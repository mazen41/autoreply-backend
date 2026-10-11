<?php

namespace App\Services;

use App\Models\CommerceOrderAttempt;
use App\Models\Conversation;
use Carbon\Carbon;

class OrderClaimGuard
{
    /** Validate any completion claim against the current, confirmed checkout. */
    public function inspect(Conversation $conversation, string $reply, array $checkoutState, array $missingFields, string $language = 'english'): array
    {
        $claimsCompletion = (bool) preg_match(
            '/\b(?:order\s+(?:has\s+been\s+)?(?:placed|submitted|confirmed|processed)|placed\s+(?:your|the)\s+order|successfully\s+placed|order\s+is\s+confirmed)\b|(?:تم\s+(?:تسجيل|إنشاء|انشاء|تأكيد|تاكيد|طلب)|طلبك\s+(?:اتسجل|تسجل|تم))\s*(?:طلبك|الطلب)?/ui',
            $reply
        );

        $askedKnownField = $this->askedForKnownField($reply, $checkoutState);
        $attempt = $claimsCompletion ? $this->matchingCompletedAttempt($conversation, $checkoutState) : null;
        $orderPresent = $attempt !== null;

        if ($claimsCompletion && !$orderPresent) {
            return [
                'allowed' => false,
                'blocked' => true,
                'requires_handoff' => true,
                'decision' => 'blocked',
                'claims_completion' => true,
                'order_record_present' => false,
                'reason' => $missingFields ? 'missing_' . $missingFields[0] : 'no_matching_completed_order',
                'replacement' => $this->replacement($missingFields, $language),
            ];
        }

        if ($askedKnownField !== null) {
            return [
                'allowed' => false,
                'blocked' => true,
                'requires_handoff' => false,
                'decision' => 'replaced',
                'claims_completion' => false,
                'order_record_present' => $orderPresent,
                'reason' => 'asks_known_' . $askedKnownField,
                'replacement' => $this->replacement($missingFields, $language),
            ];
        }

        return [
            'allowed' => true,
            'blocked' => false,
            'requires_handoff' => false,
            'decision' => 'allowed',
            'claims_completion' => $claimsCompletion,
            'order_record_present' => $orderPresent,
            'reason' => $claimsCompletion ? 'matching_completed_order' : 'no_completion_claim',
            'replacement' => null,
        ];
    }

    private function matchingCompletedAttempt(Conversation $conversation, array $state): ?CommerceOrderAttempt
    {
        $connectionId = (int) ($state['commerce_channel_id'] ?? $state['order_connection_id'] ?? 0);
        $productId = (string) ($state['commerce_external_id'] ?? $state['salla_product_id'] ?? '');
        $phone = preg_replace('/\D+/', '', (string) ($state['phone'] ?? $state['customer_phone'] ?? ''));
        $address = mb_strtolower(trim((string) ($state['address'] ?? $state['customer_address'] ?? '')));
        $summarySentAt = $state['confirmation_summary_sent_at'] ?? null;

        if ($connectionId < 1 || $productId === '' || $phone === '' || $address === '' || !$summarySentAt) {
            return null;
        }

        try {
            $summaryTime = Carbon::parse($summarySentAt);
        } catch (\Throwable) {
            return null;
        }

        $fingerprint = hash('sha256', implode('|', [$connectionId, $productId, $phone, $address]));

        return CommerceOrderAttempt::query()
            ->where('business_id', $conversation->business_id)
            ->where('conversation_id', $conversation->id)
            ->where('connection_id', $connectionId)
            ->where('checkout_fingerprint', $fingerprint)
            ->where('status', 'completed')
            ->whereNotNull('external_order_id')
            ->where('external_order_id', '!=', '')
            ->where('created_at', '>=', $summaryTime)
            ->latest('created_at')
            ->first();
    }

    private function askedForKnownField(string $reply, array $state): ?string
    {
        $patterns = [
            'full_name' => '/\b(?:what\s+(?:is|\x27s)|could\s+you|can\s+you|please|kindly|provide|share|send|tell\s+me)\b.{0,35}\b(?:full\s+)?name\b|\b(?:full\s+)?name\s*\?|\bwho\s+should\s+(?:i|we)\s+(?:address|put)\b|(?:اسمك|الاسم الكامل)/ui',
            'phone' => '/\b(?:what\s+(?:is|\x27s)|could\s+you|can\s+you|please|kindly|provide|share|send|tell\s+me)\b.{0,35}\b(?:phone|mobile|telephone)(?:\s+number)?\b|\bwhat\s+is\s+your\s+(?:phone|mobile)\b|(?:رقم هاتفك|رقم جوالك)/ui',
            'address' => '/\b(?:what\s+(?:is|\x27s)|could\s+you|can\s+you|please|kindly|provide|share|send|tell\s+me)\b.{0,35}\b(?:delivery\s+)?address\b|\bwhere\s+should\s+(?:i|we)\s+(?:deliver|send)\b|(?:عنوانك|عنوان التوصيل)/ui',
        ];

        foreach ($patterns as $field => $pattern) {
            $value = $state[$field] ?? ($field === 'phone' ? ($state['customer_phone'] ?? null) : null);
            if (!empty($value) && preg_match($pattern, $reply)) {
                return $field;
            }
        }

        return null;
    }

    private function replacement(array $missingFields, string $language): string
    {
        $labels = [
            'full_name' => 'full name',
            'phone' => 'phone number',
            'address' => 'delivery address',
            'email' => 'email address',
            'postal_code' => 'postal code',
            'geo_coordinates' => 'delivery address details',
            'building_number' => 'building number',
            'short_address' => 'short address code',
            'additional_number' => 'additional address number',
        ];

        if ($missingFields !== []) {
            $missing = array_map(fn ($field) => $labels[$field] ?? str_replace('_', ' ', $field), $missingFields);
            if ($language === 'arabic') {
                $arabic = [
                    'full_name' => 'الاسم الكامل', 'phone' => 'رقم الهاتف', 'address' => 'عنوان التوصيل',
                    'email' => 'البريد الإلكتروني', 'postal_code' => 'الرمز البريدي',
                    'geo_coordinates' => 'تفاصيل عنوان التوصيل', 'building_number' => 'رقم المبنى',
                    'short_address' => 'العنوان المختصر', 'additional_number' => 'الرقم الإضافي',
                ];
                $missing = array_map(fn ($field) => $arabic[$field] ?? $field, $missingFields);
                return 'لم يتم إرسال الطلب بعد. من فضلك أرسل: ' . implode('، ', $missing) . '.';
            }
            return 'I have not submitted the order yet. Please send: ' . implode(', ', $missing) . '.';
        }

        if ($language === 'arabic') {
            return 'لا أستطيع التأكد من إرسال الطلب، لذلك لم يتم تأكيده بعد. سيتحقق أحد أعضاء الفريق من الطلب ويتابع معك قريبًا.';
        }
        return 'I can’t verify that the order was submitted, so it is not confirmed yet. A team member will check it and follow up shortly.';
    }
}
