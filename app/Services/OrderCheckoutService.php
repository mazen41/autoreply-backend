<?php

namespace App\Services;

use App\Models\Conversation;
use Illuminate\Support\Facades\Http;

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
        if (empty($existingState['email']) && !empty($existingState['customer_email'])) {
            $existingState['email'] = $existingState['customer_email'];
        }

        // 1. Extract phone number
        $extractedPhone = null;
        $phoneSearchText = preg_replace('/(?:postal|zip)\s*(?:code)?\s*[:#-]?\s*[A-Z0-9-]{3,12}/i', '', $incomingText);
        $hasKnownPhone = !empty($existingState['phone'] ?? $existingState['customer_phone'] ?? null);
        $explicitPhoneContext = preg_match('/\b(?:phone|mobile|telephone|tel)\b/i', $incomingText)
            || preg_match('/(?:رقم الهاتف|رقم الجوال|رقمي)/u', $incomingText)
            || preg_match('/^\s*(?:\+|00)/', $incomingText);
        if ((!$hasKnownPhone || $explicitPhoneContext) && preg_match('/(?:\+?[0-9]{8,15})/', preg_replace('/\s+/', '', $phoneSearchText), $pm)) {
            $extractedPhone = $pm[0];
        }

        // Contact and delivery data that Salla requires at order submission.
        $extractedEmail = null;
        if (preg_match('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', $incomingText, $em)) {
            $extractedEmail = strtolower($em[0]);
        }

        $extractedPostalCode = null;
        if (preg_match('/(?:postal|zip)\s*(?:code)?\s*(?:(?:is|equals?)\s+|[:#-]\s*)?([A-Z0-9-]{3,12})/i', $incomingText, $postalMatch)) {
            $extractedPostalCode = trim($postalMatch[1]);
        } elseif (empty($existingState['postal_code']) && preg_match('/^\s*([0-9]{4,10})\s*$/', $incomingText, $postalMatch)) {
            // A bare numeric reply is accepted only while Salla is missing a postal code.
            $extractedPostalCode = $postalMatch[1];
        }

        $extractedCoordinates = null;
        $decodedText = rawurldecode($incomingText);
        $hasLocationContext = (bool) preg_match('/\b(?:map|maps|pin|coordinates?|latitude|longitude)\b/i', $decodedText);
        $hasCoordinates = preg_match('/!3d(-?\d{1,2}(?:\.\d+)?)!4d(-?\d{1,3}(?:\.\d+)?)/i', $decodedText, $coordinateMatch)
            || ($hasLocationContext && preg_match('/(?:@|q=|[?&]|coordinates?\s*[:=]?|pin\s*[:=]?)\s*(-?\d{1,2}(?:\.\d+)?)\s*[,;]\s*(-?\d{1,3}(?:\.\d+)?)/i', $decodedText, $coordinateMatch))
            || preg_match('/\b(-?\d{1,2}\.\d{3,})\s*,\s*(-?\d{1,3}\.\d{3,})\b/', $decodedText, $coordinateMatch);

        // Google Maps short links hide coordinates behind redirects.
        if (!$hasCoordinates && ($expandedMapsUrl = $this->expandGoogleMapsShortLink($incomingText))) {
            $hasCoordinates = preg_match('/!3d(-?\d{1,2}(?:\.\d+)?)!4d(-?\d{1,3}(?:\.\d+)?)/i', $expandedMapsUrl, $coordinateMatch)
                || preg_match('/(?:@|q=|[?&])\s*(-?\d{1,2}(?:\.\d+)?)\s*[,;]\s*(-?\d{1,3}(?:\.\d+)?)/i', $expandedMapsUrl, $coordinateMatch);
        }

        if ($hasCoordinates) {
            $latitude = (float) $coordinateMatch[1];
            $longitude = (float) $coordinateMatch[2];
            if ($latitude >= -90 && $latitude <= 90 && $longitude >= -180 && $longitude <= 180) {
                $extractedCoordinates = ['lat' => $latitude, 'lng' => $longitude];
            }
        }

        $extractedBuildingNumber = null;
        if (preg_match('/(?:building|house|villa)\s*(?:number|no\.?|#)?\s*(?:(?:is|equals?)\s+|[:#-]\s*)?([A-Z0-9-]+)/i', $incomingText, $buildingMatch)) {
            $extractedBuildingNumber = trim($buildingMatch[1]);
        }

        $extractedShortAddress = null;
        if (preg_match('/(?:short address|national address|العنوان المختصر)\s*(?:(?:is|equals?)\s+|[:#-]\s*)?([A-Z0-9]{4,12})/iu', $incomingText, $shortAddressMatch)) {
            $extractedShortAddress = strtoupper($shortAddressMatch[1]);
        }

        $extractedAdditionalNumber = null;
        if (preg_match('/(?:additional number|secondary number|الرقم الإضافي)\s*(?:(?:is|equals?)\s+|[:#-]\s*)?([A-Z0-9-]{2,12})/iu', $incomingText, $additionalNumberMatch)) {
            $extractedAdditionalNumber = trim($additionalNumberMatch[1]);
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
            if (
                count($words) >= 1
                && count($words) <= 4
                && !preg_match('/[0-9@]/', $incomingText)
                && !preg_match('/https?:\/\/|maps\.app|maps\.google/i', $incomingText)
            ) {
                $textLower = mb_strtolower(trim($incomingText));
                if (!preg_match('/(?:hi|hello|hey|yes|no|ok|sure|thanks?|great|good|perfect|nice|cool|awesome|excellent|wonderful|fine|please|order|buy|address|confirm|products?|items?|images?|pictures?|photos?|the dress|this one|that one|dress|see|view|show|list|help|catalogue|catalog|مرحبا|سلام|نعم|شكرا|اريد|طلب|تأكيد|تم|اكد|الفستان|هذا|هذه)/ui', $textLower)) {
                    $extractedName = trim($incomingText);
                }
            }
        }

        // 3. Extract address heuristics — ONLY from explicit address statements
        $extractedAddress = null;
        $addressPatterns = [
            '/(?:my address is|address is|delivery address|live in|located at|العنوان|عنواني|حي|شارع|مدينة|محافظة|الرياض|جدة|مكة|الدمام|القاهرة|الإسكندرية)\s*[:\-]?\s*(.+)/ui',
        ];
        foreach ($addressPatterns as $pattern) {
            if (preg_match($pattern, $incomingText, $am)) {
                $candidate = trim($am[1]);
                // Validate: address must be meaningful (at least 4 chars, not just a product name or greeting)
                if (strlen($candidate) >= 4
                    && !preg_match('/^(?:hi|hello|hey|yes|no|ok|sure|thanks|the dress|this one|that one|مرحبا|سلام|نعم|لا|حسنا|شكرا|الفستان|هذا|هذه)$/ui', $candidate)) {
                    $extractedAddress = $candidate;
                    break;
                }
            }
        }

        // When a product is already in checkout and the customer still owes an
        // address, they often answer with only the address (without repeating
        // "my address is"). Accept recognizable street/city formats in that
        // narrow context; never treat arbitrary chat as a delivery address.
        if (!$extractedAddress
            && !empty($existingState['salla_product_id'])
            && empty($existingState['address'])
            && empty($existingState['customer_address'])
            && mb_strlen(trim($incomingText)) >= 8
            && preg_match('/(?:\b\d{1,5}\s+\p{L}|\b(?:street|st\.?|road|rd\.?|avenue|ave\.?|district|city|giza|cairo|riyadh|jeddah|mecca|dammam)\b|Ø´Ø§Ø±Ø¹|Ù…Ø¯ÙŠÙ†Ø©|Ø­ÙŠ)/iu', $incomingText)) {
            $extractedAddress = trim($incomingText);
        }

        // NEVER infer address from arbitrary conversation text.
        // Address must come from an explicit address statement or AI-validated extraction.

        // Phone: NEVER use sender_id (Instagram/Meta ID) as phone number.
        // Only use explicitly extracted phone or previously stored phone.
        $existingPhone = $existingState['phone'] ?? null;
        $existingName  = $existingState['full_name'] ?? ($conversation->sender_name !== $conversation->sender_id ? $conversation->sender_name : null);

        // Merge fields: preserve existing values if new extraction is null (NO OVERWRITING WITH NULL!)
        $mergedState = array_merge($existingState, array_filter([
            'salla_product_id' => $referencedProduct['salla_product_id'] ?? ($existingState['salla_product_id'] ?? null),
            'sku'              => $referencedProduct['sku']              ?? ($existingState['sku']              ?? null),
            'product_name'     => $referencedProduct['name']             ?? ($existingState['product_name']     ?? null),
            'product_price'    => $referencedProduct['price']            ?? ($existingState['product_price']    ?? null),
            'product_currency' => $referencedProduct['currency']         ?? ($existingState['product_currency'] ?? null),
            'full_name'        => $extractedName                     ?? $existingName,
            'phone'            => $extractedPhone                    ?? $existingPhone,
            'customer_phone'   => $extractedPhone                    ?? $existingPhone, // Alias for backward compatibility
            'address'          => $extractedAddress                  ?? ($existingState['address']          ?? null),
            'email'            => $extractedEmail                    ?? ($existingState['email']             ?? null),
            'customer_email'   => $extractedEmail                    ?? ($existingState['customer_email']    ?? $existingState['email'] ?? null),
            'postal_code'      => $extractedPostalCode               ?? ($existingState['postal_code']       ?? null),
            'geo_coordinates'  => $extractedCoordinates              ?? ($existingState['geo_coordinates']   ?? null),
            'building_number'  => $extractedBuildingNumber           ?? ($existingState['building_number']   ?? null),
            'short_address'    => $extractedShortAddress             ?? ($existingState['short_address']     ?? null),
            'additional_number'=> $extractedAdditionalNumber         ?? ($existingState['additional_number'] ?? null),
            'updated_at'       => now()->toISOString(),
        ], fn($v) => !is_null($v) && $v !== ''));

        // A plain greeting ("Hi") or catalogue request ("Can i see the images")
        // must NOT create a phantom order context. checkout_state only becomes
        // meaningful once real order data exists (product selection or
        // collected customer fields) — never from arbitrary chatter.
        $hasOrderData = !empty($mergedState['salla_product_id'])
            || !empty($mergedState['product_name'])
            || !empty($mergedState['product_price'])
            || !empty($mergedState['full_name'])
            || !empty($mergedState['phone'])
            || !empty($mergedState['address'])
            || !empty($mergedState['order_id'])
            || !empty($mergedState['status'])
            || !empty($mergedState['confirmation_state'])
            || !empty($mergedState['external_source']);

        if (!$hasOrderData) {
            return [];
        }

        // Default currency applies only once a product is actually in the order context
        if (empty($mergedState['product_currency'])
            && (!empty($mergedState['salla_product_id']) || !empty($mergedState['product_name']))) {
            $mergedState['product_currency'] = 'SAR';
        }

        return $mergedState;
    }

    /** Resolve only Google Maps short links; return the final URL without logging it. */
    private function expandGoogleMapsShortLink(string $text): ?string
    {
        // Stop at Markdown link delimiters as well as whitespace; otherwise a
        // pasted [URL](URL) can turn into an invalid combined redirect target.
        if (!preg_match('/https?:\/\/[^\s<>\]\)]+/i', $text, $match)) {
            return null;
        }

        $url = rtrim($match[0], '.,);]');
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        if (!in_array($host, ['maps.app.goo.gl', 'goo.gl'], true)) {
            return null;
        }

        for ($redirects = 0; $redirects < 6; $redirects++) {
            if (!$this->isAllowedMapsRedirectHost($host)) {
                return null;
            }

            try {
                $response = Http::timeout(4)
                    ->connectTimeout(2)
                    ->withOptions(['allow_redirects' => false])
                    ->get($url);
            } catch (\Throwable) {
                return null;
            }

            if (!in_array($response->status(), [301, 302, 303, 307, 308], true)) {
                return $url;
            }

            $location = $response->header('Location');
            if (!is_string($location) || trim($location) === '') {
                return null;
            }

            $url = $this->resolveRedirectUrl($url, trim($location));
            if (!$url) {
                return null;
            }
            $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        }

        return null;
    }

    private function isAllowedMapsRedirectHost(string $host): bool
    {
        return in_array($host, ['maps.app.goo.gl', 'goo.gl', 'google.com', 'www.google.com', 'maps.google.com'], true);
    }

    private function resolveRedirectUrl(string $baseUrl, string $location): ?string
    {
        if (preg_match('/^https?:\/\//i', $location)) {
            return $location;
        }

        $base = parse_url($baseUrl);
        if (empty($base['scheme']) || empty($base['host'])) {
            return null;
        }

        $origin = $base['scheme'] . '://' . $base['host']
            . (isset($base['port']) ? ':' . $base['port'] : '');
        if (str_starts_with($location, '//')) {
            return $base['scheme'] . ':' . $location;
        }
        if (str_starts_with($location, '/')) {
            return $origin . $location;
        }
        if (str_starts_with($location, '?')) {
            return $origin . ($base['path'] ?? '/') . $location;
        }

        $directory = rtrim(dirname($base['path'] ?? '/'), '/');
        return $origin . ($directory !== '' ? $directory : '') . '/' . $location;
    }

    /**
     * Compute known_fields and missing_fields maps.
     */
    public function computeFieldStatus(array $state, bool $requireSallaShippingDetails = false): array
    {
        // Normalize legacy field aliases
        $phoneValue = $state['phone'] ?? $state['customer_phone'] ?? null;
        $nameValue  = $state['full_name'] ?? $state['customer_name'] ?? null;

        $normalizedState = array_merge($state, array_filter([
            'phone'     => $phoneValue,
            'full_name' => $nameValue,
        ]));

        $requiredFields = self::REQUIRED_FIELDS;
        if ($requireSallaShippingDetails) {
            $requiredFields = array_merge($requiredFields, [
                'email', 'postal_code', 'geo_coordinates', 'building_number',
            ]);

            // Saudi National Address codes are not applicable to international
            // delivery addresses. Require them only for Saudi phone/address flows.
            $phoneDigits = preg_replace('/[^0-9]/', '', (string) ($state['phone'] ?? $state['customer_phone'] ?? ''));
            $isSaudiNumber = str_starts_with($phoneDigits, '9665')
                || (strlen($phoneDigits) === 10 && str_starts_with($phoneDigits, '05'));
            if ($isSaudiNumber) {
                $requiredFields = array_merge($requiredFields, ['short_address', 'additional_number']);
            }
        }

        $fieldAliases = [
            'email' => ['customer_email'],
        ];
        $known = [];
        foreach ($requiredFields as $field) {
            $value = $normalizedState[$field] ?? null;
            foreach ($fieldAliases[$field] ?? [] as $alias) {
                $value = $value ?: ($normalizedState[$alias] ?? null);
            }
            if ($field === 'email' && $value && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
                $value = null;
            }
            if ($field === 'geo_coordinates' && is_array($value)) {
                $validCoordinates = isset($value['lat'], $value['lng'])
                    && is_numeric($value['lat'])
                    && is_numeric($value['lng'])
                    && (float) $value['lat'] >= -90
                    && (float) $value['lat'] <= 90
                    && (float) $value['lng'] >= -180
                    && (float) $value['lng'] <= 180;
                $value = $validCoordinates ? $value : null;
            }
            if (!empty($value)) {
                $known[$field] = $value;
            }
        }

        $missing = [];
        foreach ($requiredFields as $field) {
            if (empty($known[$field])) {
                $missing[] = $field;
            }
        }

        return [
            'known_fields'   => $known,
            'missing_fields' => $missing,
            'is_complete'    => empty($missing),
            'required_fields' => $requiredFields,
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
