<?php

namespace App\Services;

/** Recognises a clear wish to order (English and Egyptian Arabic), used before collecting checkout details. */
class ShopifyOrderIntent
{
    public static function wantsToOrder(string $text): bool
    {
        $text = mb_strtolower(trim($text));
        if ($text === '') {
            return false;
        }

        return preg_match(
            '/\b(?:order|orders|buy|purchase|checkout)\b|\bplace\s+(?:an?\s+|this\s+|the\s+|my\s+)?order\b|اطلب|أطلب|اشتري|أشتري|هاخد|هاخذه|هاخدها|عايز\s+اطلب|عاوز\s+اطلب/u',
            $text
        ) === 1;
    }
}