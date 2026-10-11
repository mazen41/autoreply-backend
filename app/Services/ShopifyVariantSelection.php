<?php

namespace App\Services;

/** Resolve a customer's text to a synced Shopify variant without guessing. */
class ShopifyVariantSelection
{
    public static function available(array $variants): array
    {
        return array_values(array_filter($variants, static function ($variant): bool {
            return is_array($variant)
                && !empty($variant['id'])
                && preg_match('~/ProductVariant/\d+$~', (string) $variant['id']) === 1
                && ($variant['availableForSale'] ?? true) !== false;
        }));
    }

    public static function match(array $variants, string $text): ?array
    {
        $available = self::available($variants);
        $normalized = mb_strtolower(trim($text));
        if ($normalized === '') {
            return null;
        }

        if (preg_match('/^(?:(?:option|variant|number|no\.?|#)\s*)?([1-9]\d*)$/iu', $normalized, $number)) {
            return $available[(int) $number[1] - 1] ?? null;
        }

        $exactMatches = [];
        foreach ($available as $variant) {
            foreach (['title', 'sku', 'id'] as $field) {
                $value = mb_strtolower(trim((string) ($variant[$field] ?? '')));
                if ($value !== '' && $normalized === $value) {
                    $exactMatches[(string) $variant['id']] = $variant;
                }
            }
        }
        if (count($exactMatches) === 1) {
            return array_values($exactMatches)[0];
        }
        if (count($exactMatches) > 1) {
            return null;
        }

        $matches = array_values(array_filter($available, static function (array $variant) use ($normalized): bool {
            foreach (['title', 'sku'] as $field) {
                $value = mb_strtolower(trim((string) ($variant[$field] ?? '')));
                if (mb_strlen($value) >= 3 && str_contains($normalized, $value)) {
                    return true;
                }
            }
            return false;
        }));

        return count($matches) === 1 ? $matches[0] : null;
    }
}
