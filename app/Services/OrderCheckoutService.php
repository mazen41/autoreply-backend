<?php

namespace App\Services;

use App\Models\Conversation;

class OrderCheckoutService
{
    /**
     * Required fields for order completion.
     */
    public const REQUIRED_FIELDS = ['full_name', 'phone', 'address'];

    /**
     * Extract order fields from incoming message text and merge with existing checkout_state.
     */
    public function extractAndMergeState(Conversation $conversation, string $incomingText, ?array $referencedProduct = null): array
    {
        $existingState = is_array($conversation->checkout_state) ? $conversation->checkout_state : [];

        // 1. Extract phone number
        $extractedPhone = null;
        if (preg_match('/(?:\+?[0-9]{8,15})/', preg_replace('/\s+/', '', $incomingText), $pm)) {
            $extractedPhone = $pm[0];
        }

        // 2. Extract full name heuristics (if message is a name response or contains name patterns)
        $extractedName = null;
        $namePatterns = [
            '/(?:my name is|i am|name is|اسمى|اسمي|أنا|انا|اسمي هو|معاكم)\s+([A-Za-z\x{0600}-\x{06FF}\s]{2,40})/ui',
        ];
        foreach ($namePatterns as $pattern) {
            if (preg_match($pattern, $incomingText, $nm)) {
                $candidate = trim($nm[1]);
                if (strlen($candidate) >= 2 && !preg_match('/(?:order|buy|help|address|phone|price|product|طلب|شراء|عنوان)/ui', $candidate)) {
                    $extractedName = $candidate;
                    break;
                }
            }
        }

        // Fallback: If customer is answering a direct name query and message is short (2-4 words, no numbers/keywords)
        if (!$extractedName && empty($existingState['full_name'])) {
            $words = array_filter(explode(' ', trim($incomingText)));
            if (count($words) >= 1 && count($words) <= 4 && !preg_match('/[0-9]/', $incomingText)) {
                $textLower = mb_strtolower(trim($incomingText));
                if (!preg_match('/(?:hi|hello|yes|no|ok|sure|thanks|order|buy|address|مرحبا|سلام|نعم|شكرا|اريد|طلب)/ui', $textLower)) {
                    $extractedName = trim($incomingText);
                }
            }
        }

        // 3. Extract address heuristics
        $extractedAddress = null;
        $addressPatterns = [
            '/(?:my address is|address is|delivery address|live in|located at|العنوان|عنواني|حي|شارع|مدينة|محافظة|الرياض|جدة|مكة|الدمام|القاهرة|الإسكندرية)\s*[:\-]?\s*(.+)/ui',
        ];
        foreach ($addressPatterns as $pattern) {
            if (preg_match($pattern, $incomingText, $am)) {
                $extractedAddress = trim($am[1]);
                break;
            }
        }

        // Fallback address if address is the only missing field and text has address-like length
        if (!$extractedAddress && empty($existingState['address']) && !empty($existingState['full_name']) && !empty($existingState['phone'])) {
            if (strlen(trim($incomingText)) >= 4 && !preg_match('/[0-9]{8,}/', $incomingText)) {
                $extractedAddress = trim($incomingText);
            }
        }

        // Merge fields: preserve existing values if new extraction is null (NO OVERWRITING WITH NULL!)
        $mergedState = array_merge($existingState, array_filter([
            'salla_product_id' => $referencedProduct['salla_product_id'] ?? ($existingState['salla_product_id'] ?? null),
            'sku'              => $referencedProduct['sku']              ?? ($existingState['sku']              ?? null),
            'product_name'     => $referencedProduct['name']             ?? ($existingState['product_name']     ?? null),
            'product_price'    => $referencedProduct['price']            ?? ($existingState['product_price']    ?? null),
            'product_currency' => $referencedProduct['currency']         ?? ($existingState['product_currency'] ?? 'SAR'),
            'full_name'        => $extractedName                     ?? ($existingState['full_name']        ?? ($conversation->sender_name !== $conversation->sender_id ? $conversation->sender_name : null)),
            'phone'            => $extractedPhone                    ?? ($existingState['phone']            ?? ($conversation->sender_id ?: null)),
            'address'          => $extractedAddress                  ?? ($existingState['address']          ?? null),
            'updated_at'       => now()->toISOString(),
        ], fn($v) => !is_null($v) && $v !== ''));

        return $mergedState;
    }

    /**
     * Compute known_fields and missing_fields maps.
     */
    public function computeFieldStatus(array $state): array
    {
        $known = [];
        foreach (self::REQUIRED_FIELDS as $field) {
            if (!empty($state[$field])) {
                $known[$field] = $state[$field];
            }
        }

        $missing = [];
        foreach (self::REQUIRED_FIELDS as $field) {
            if (empty($known[$field])) {
                $missing[] = $field;
            }
        }

        return [
            'known_fields'   => $known,
            'missing_fields' => $missing,
            'is_complete'    => empty($missing),
        ];
    }
}
