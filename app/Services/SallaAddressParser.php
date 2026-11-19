<?php

namespace App\Services;

/**
 * Pulls the Salla delivery fields out of a customer's typed address and reports what is missing.
 * Nothing is guessed: a field is filled only when the text states it clearly.
 */
class SallaAddressParser
{
    /** Remove conversational labels accidentally saved in older checkout addresses. */
    public static function cleanAddress(string $text): ?string
    {
        $text = trim($text, " \t\n\r\0\x0B,;:-");

        for ($i = 0; $i < 5; $i++) {
            $cleaned = preg_replace(
                '/^(?:(?:and\s+)?(?:(?:the|my)\s+)?(?:delivery\s+)?address\s*(?:(?:is|:|-)\s*)?|is\s+and\s+|here\s+)/iu',
                '',
                $text,
                1
            ) ?? $text;
            $cleaned = trim($cleaned, " \t\n\r\0\x0B,;:-");
            if ($cleaned === $text) {
                break;
            }
            $text = $cleaned;
        }

        // Drop a phone/email clause accidentally captured after the address.
        $text = preg_replace('/\s+(?:and\s+)?(?:the\s+)?(?:phone|mobile|email)\b.*$/iu', '', $text) ?? $text;
        $text = trim($text, " \t\n\r\0\x0B,;:-");

        return $text === '' ? null : $text;
    }

    /** Fills empty fields from the stored address text; never overwrites a value already saved. */
    public static function fillMissing(array $state): array
    {
        $address = (string) ($state['address'] ?? '');
        if ($address !== '' && ($cleanAddress = self::cleanAddress($address))) {
            $state['address'] = $address = $cleanAddress;
        }
        if (!empty($state['address_line']) && ($cleanLine = self::cleanAddress((string) $state['address_line']))) {
            $state['address_line'] = $cleanLine;
        }
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

        foreach (['street_number', 'short_address', 'additional_number'] as $key) {
            if (!empty($state[$key])) {
                $state[$key] = trim((string) $state[$key]);
            }
        }

        return $state;
    }

    /** @return array{building_number: ?string, postal_code: ?string, block: ?string, district: ?string, address_line: ?string} */
    public static function parse(string $text): array
    {
        $text = trim($text);

        // A bare confirmation/cancellation reply ("confirm order", "تأكيد",
        // "cancel", ...) is not delivery address text. Without this guard,
        // streetLine() happily returns the leftover text as `address_line`,
        // clobbering the customer's real street address with the literal
        // words "confirm order" and permanently breaking checkout (see the
        // Salla 422 infinite-loop bug).
        if (self::isConfirmationOrCancellationOnly($text)) {
            return [
                'building_number' => null,
                'street_number' => null,
                'postal_code' => null,
                'block' => null,
                'district' => null,
                'short_address' => null,
                'additional_number' => null,
                'address_line' => null,
            ];
        }

        return [
            'building_number' => OrderCheckoutService::extractBuildingNumber($text),
            'street_number' => self::labelled($text, '/(?:street\s*(?:number|no\.?|#)|رقم\s*(?:الشارع|الطريق))\s*(?:is\s+|[:=#-]\s*)?(\d{1,8}[A-Za-z]?)/iu'),
            'postal_code' => self::postalCode($text),
            'block' => self::block($text),
            'district' => self::labelled($text, '/(?:district|neighborhood|area|حي|منطقة)\s*[:#-]?\s*(\p{L}[\p{L}\s\-]{1,30}?)(?=\s*(?:,|$|\b(?:street|st|block|postal|zip|and)\b))/iu'),
            'short_address' => self::labelled($text, '/(?:short\s*(?:national\s*)?address|national\s*address\s*(?:code|number)?|العنوان\s*الوطني\s*(?:المختصر)?|العنوان\s*المختصر)\s*(?:is\s+|[:=#-]\s*)?([A-Z]{4}\d{4})/iu'),
            'additional_number' => self::labelled($text, '/(?:additional\s*(?:address\s*)?number|secondary\s*number|الرقم\s*الإضافي)\s*(?:is\s+|[:=#-]\s*)?(\d{1,8})/iu'),
            'address_line' => self::streetLine($text),
        ];
    }

    /** True when the whole message is just a confirm/cancel reply, in English or Arabic. */
    private static function isConfirmationOrCancellationOnly(string $text): bool
    {
        return preg_match(
            '/^(?:'
            . 'confirm(?:\s+(?:the\s+)?order)?'
            . '|cancel(?:\s+(?:the\s+)?order)?'
            . '|يلغي|إلغاء|الغاء|إلغاء\s+الطلب|الغاء\s+الطلب'
            . '|تأكيد(?:\s+الطلب)?|أكد(?:\s+الطلب)?'
            . ')\s*[.!؟?]*$/iu',
            $text
        ) === 1;
    }

    private static function block(string $text): ?string
    {
        // Salla's `block` field is also used for floor details (their API
        // example is "الدور الثاني"). Accept explicit labels only; never
        // guess a block from a P.O. Box, city, street, or postal code.
        $labelledBlock = self::labelled($text, '/(?:block\s*\/\s*floor|floor\s*\/\s*block|block|blk|bl\.?|بلوك|قطعة|floor|level|apartment|apt\.?|unit|الدور|الطابق|الطابقه|شقة|وحدة)\s*(?:no\.?|number|#)?\s*(?:(?:is|equals?)\s+|[:=\-#]\s*)?([\p{L}\d][\p{L}\d\s\-\/]{0,30}?)(?=\s*(?:[,;.؟]|$|\b(?:street|st|district|area|postal|zip|short\s*(?:national\s*)?address|additional\s*(?:address\s*)?number|and)\b))/iu');
        if ($labelledBlock !== null) {
            return self::notApplicable($labelledBlock) ? '__not_applicable__' : $labelledBlock;
        }

        return null;
    }

    private static function notApplicable(string $value): bool
    {
        return in_array(mb_strtolower(trim($value)), ['n/a', 'na', 'none', 'not applicable', 'لا يوجد', 'غير متوفر'], true);
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
        // Confirm/cancel words are commands, not address text. Strip them so a message
        // like "confirm order" can never be stored as address_line.
        $line = preg_replace('/\b(?:confirm(?:\s+order)?|cancel)\b|تأكيد(?:\s+الطلب)?|إلغاء/iu', ' ', $line) ?? $line;
        $line = trim(preg_replace('/[\s,،]+/u', ' ', $line) ?? $line, " ,،.-");

        return $line === '' ? null : $line;
    }
}
