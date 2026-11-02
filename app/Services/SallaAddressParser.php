<?php

namespace App\Services;

/**
 * Pulls the Salla delivery fields out of a customer's typed address and reports what is missing.
 * Nothing is guessed: a field is filled only when the text states it clearly.
 */
class SallaAddressParser
{
    /** Fills empty fields from the stored address text; never overwrites a value already saved. */
    public static function fillMissing(array $state): array
    {
        $address = (string) ($state['address'] ?? '');
        $poBox = $state['po_box'] ?? OrderCheckoutService::extractPoBox($address);
        if (!empty($poBox)) {
            $state['po_box'] = (string) $poBox;
        }

        // Older checkout turns could persist "is 122" as the block and 122 as
        // the building number from "P.O. Box 122". Repair those values before
        // calculating missing fields; a postal box is never a street number or
        // a Saudi block/floor.
        if (!empty($state['block'])) {
            $block = trim((string) $state['block']);
            $block = preg_replace('/^(?:is|equals?)\s+/iu', '', $block) ?? $block;
            $block = trim($block, " ,.-");
            if ($block === '' || (!empty($poBox) && preg_match('/^\d+$/', $block) && (string) $block === (string) $poBox)) {
                unset($state['block']);
            } else {
                $state['block'] = $block;
            }
        }

        foreach (['building_number', 'street_number'] as $numberField) {
            if (!empty($state[$numberField]) && !empty($poBox)
                && (string) $state[$numberField] === (string) $poBox) {
                unset($state[$numberField]);
            }
        }

        // A one-word city copied into full_name is a common false match when
        // the customer sends the address separately (e.g. "Hofuf").
        $name = trim((string) ($state['full_name'] ?? ''));
        if ($name !== '' && !preg_match('/\s/u', $name) && $address !== ''
            && preg_match('/(?<![\p{L}])' . preg_quote($name, '/') . '(?![\p{L}])/iu', $address)) {
            unset($state['full_name']);
        }

        foreach (self::parse((string) ($state['address'] ?? '')) as $key => $value) {
            if ($value !== null && empty($state[$key])) {
                $state[$key] = $value;
            }
        }

        return $state;
    }

    /** @return array{building_number: ?string, postal_code: ?string, block: ?string, district: ?string, address_line: ?string} */
    public static function parse(string $text): array
    {
        $text = trim($text);

        return [
            'building_number' => OrderCheckoutService::extractBuildingNumber($text),
            'postal_code' => self::postalCode($text),
            'block' => self::block($text),
            'district' => self::labelled($text, '/(?:district|neighborhood|area|حي|منطقة)\s*[:#-]?\s*(\p{L}[\p{L}\s\-]{1,30}?)(?=\s*(?:,|$|\b(?:street|st|block|postal|zip|and)\b))/iu'),
            'address_line' => self::streetLine($text),
        ];
    }

    private static function block(string $text): ?string
    {
        // Salla's `block` field is also used for floor details (their API
        // example is "الدور الثاني"). Accept explicit labels only; never
        // guess a block from a P.O. Box, city, street, or postal code.
        $labelledBlock = self::labelled($text, '/(?:block|blk|bl\.?|بلوك|قطعة)\s*(?:no\.?|number|#)?\s*(?:(?:is|equals?)\s+|[:=\-#]\s*)?([\p{L}\d][\p{L}\d\s\-\/]{0,30}?)(?=\s*(?:,|$|\b(?:street|st|district|area|postal|zip|and)\b))/iu');
        if ($labelledBlock !== null) {
            return $labelledBlock;
        }

        return self::labelled($text, '/(?:floor|level|apartment|apt\.?|unit|الدور|الطابق|الطابقه|شقة|وحدة)\s*(?:(?:is|number|no\.?|رقم)\s+|[:=#\-]\s*)?([\p{L}\d][\p{L}\d\s\-\/]{0,25}?)(?=\s*(?:,|$|\b(?:street|st|district|area|postal|zip|and)\b))/iu');
    }

    private static function postalCode(string $text): ?string
    {
        if (preg_match('/(?:postal\s*code|post\s*code|postcode|zip(?:\s*code)?|الرمز\s*البريدي|رمز\s*البريد)\D{0,12}(\d{5})(?!\d)/iu', $text, $m) === 1) {
            return $m[1];
        }
        if (preg_match('/(?<![\d+])(\d{5})(?![\d])/', $text, $m) === 1) {
            return $m[1];
        }

        return null;
    }

    private static function labelled(string $text, string $pattern): ?string
    {
        if (preg_match($pattern, $text, $m) === 1) {
            $value = trim($m[1], " ,.-");

            return $value === '' ? null : $value;
        }

        return null;
    }

    /** The street part: the text with building number, postal code and address labels removed. */
    private static function streetLine(string $text): ?string
    {
        $line = preg_replace('/(?:postal\s*code|post\s*code|postcode|zip(?:\s*code)?|الرمز\s*البريدي|رمز\s*البريد)\s*(?:is|=|:)?\s*\d{5}/iu', ' ', $text) ?? $text;
        $line = preg_replace('/(?<![\d+])\d{5}(?!\d)/', ' ', $line) ?? $line;
        $line = preg_replace('/\b(?:the\s+)?(?:delivery\s+)?address\s*(?:is|:)\s*/i', ' ', $line) ?? $line;
        $line = preg_replace('/\b(?:building|house|villa|apt|apartment|flat)\s*(?:number|no\.?|#)?\s*(?:is\s+)?\d{1,6}[A-Za-z]?\b/i', ' ', $line) ?? $line;
        $line = preg_replace('/^\s*\d{1,5}[A-Za-z]?\s+(?=\p{L})/u', '', $line) ?? $line;
        $line = preg_replace('/\s+(?:and|و)\s*$/iu', '', $line) ?? $line;
        $line = trim(preg_replace('/[\s,،]+/u', ' ', $line) ?? $line, " ,،.-");

        return $line === '' ? null : $line;
    }
}
