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

        // Normalize legacy field names to canonical standard (phone, full_name)
        if (empty($existingState['phone']) && !empty($existingState['customer_phone'])) {
            $existingState['phone'] = $existingState['customer_phone'];
        }
        if (empty($existingState['full_name']) && !empty($existingState['customer_name'])) {
            $existingState['full_name'] = $existingState['customer_name'];
        }

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
                if (strlen($candidate) >= 2 && !preg_match('/(?:order|buy|help|address|phone|price|product|طلب|شراء|عنوان|هاتف|جوال)/ui', $candidate)) {
                    $extractedName = $candidate;
                    break;
                }
            }
        }

        // Fallback: If customer is answering a direct name query and message is short (1-4 words, no numbers/keywords)
        if (!$extractedName && empty($existingState['full_name'])) {
            $words = array_filter(explode(' ', trim($incomingText)));
            if (count($words) >= 1 && count($words) <= 4 && !preg_match('/[0-9]/', $incomingText)) {
                $textLower = mb_strtolower(trim($incomingText));
                if (!preg_match('/(?:hi|hello|yes|no|ok|sure|thanks|order|buy|address|confirm|مرحبا|سلام|نعم|شكرا|اريد|طلب|تأكيد|تم|اكد)/ui', $textLower)) {
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

        // Fallback address if address is empty and text does not match explicit confirmation or standalone phone
        if (!$extractedAddress && empty($existingState['address'])) {
            $textLower = mb_strtolower(trim($incomingText));
            $confirmKeywords = '/^(?:yes|yeah|sure|ok|okay|confirm|placed|thanks|نعم|تأكيد|تم|موافق|شكرا|اكد)$/ui';
            if (!preg_match($confirmKeywords, $textLower)
                && strlen(trim($incomingText)) >= 4
                && !preg_match('/^\+?[0-9]{8,15}$/', trim($incomingText))) {
                $extractedAddress = trim($incomingText);
            }
        }

        $existingPhone = $existingState['phone'] ?? ($conversation->sender_id ?: null);
        $existingName  = $existingState['full_name'] ?? ($conversation->sender_name !== $conversation->sender_id ? $conversation->sender_name : null);

        // Merge fields: preserve existing values if new extraction is null (NO OVERWRITING WITH NULL!)
        $mergedState = array_merge($existingState, array_filter([
            'salla_product_id' => $referencedProduct['salla_product_id'] ?? ($existingState['salla_product_id'] ?? null),
            'sku'              => $referencedProduct['sku']              ?? ($existingState['sku']              ?? null),
            'product_name'     => $referencedProduct['name']             ?? ($existingState['product_name']     ?? null),
            'product_price'    => $referencedProduct['price']            ?? ($existingState['product_price']    ?? null),
            'product_currency' => $referencedProduct['currency']         ?? ($existingState['product_currency'] ?? 'SAR'),
            'full_name'        => $extractedName                     ?? $existingName,
            'phone'            => $extractedPhone                    ?? $existingPhone,
            'customer_phone'   => $extractedPhone                    ?? $existingPhone, // Alias for backward compatibility
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
        // Normalize legacy field aliases
        $phoneValue = $state['phone'] ?? $state['customer_phone'] ?? null;
        $nameValue  = $state['full_name'] ?? $state['customer_name'] ?? null;

        $normalizedState = array_merge($state, array_filter([
            'phone'     => $phoneValue,
            'full_name' => $nameValue,
        ]));

        $known = [];
        foreach (self::REQUIRED_FIELDS as $field) {
            if (!empty($normalizedState[$field])) {
                $known[$field] = $normalizedState[$field];
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

    /**
     * Manage deterministic confirmation state transitions:
     * - 'collecting_info': details are missing
     * - 'confirmation_pending': all details present, awaiting explicit customer confirmation
     * - 'confirmed': customer sent an explicit confirmation message
     * - 'order_placed': external order created successfully
     */
    public function updateConfirmationState(array $state, string $incomingText, bool $isComplete): array
    {
        $currentState = $state['confirmation_state'] ?? null;
        $orderId = $state['order_id'] ?? null;

        if (!empty($orderId) && ($state['status'] ?? '') === 'completed') {
            $state['confirmation_state'] = 'order_placed';
            return $state;
        }

        if (!$isComplete) {
            $state['confirmation_state'] = 'collecting_info';
            return $state;
        }

        // All fields complete — check if customer message explicitly confirms
        $incomingLower = mb_strtolower(trim($incomingText));
        $confirmPatterns = '/^(?:yes|yeah|yep|sure|ok|okay|confirm|please confirm|place order|نعم|أكد|اكد|تأكيد|تم|موافق|تم التأكيد|اعتمد|اشتري|اطلب)$/ui';
        $containsConfirmPhrase = (bool)preg_match($confirmPatterns, $incomingLower)
            || (bool)preg_match('/(?:yes confirm|confirm please|confirm order|place the order|نعم أكد|تأكيد الطلب|اعتمد الطلب|أكد الطلب)/ui', $incomingLower);

        if ($containsConfirmPhrase) {
            $state['confirmation_state'] = 'confirmed';
        } elseif (empty($currentState) || $currentState === 'collecting_info') {
            $state['confirmation_state'] = 'confirmation_pending';
        }

        return $state;
    }
}
