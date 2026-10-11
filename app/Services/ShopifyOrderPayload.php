<?php

namespace App\Services;

/**
 * Builds the body for POST /admin/api/{version}/orders.json.
 *
 * Shopify ignores billing and shipping addresses unless both first_name and
 * last_name are present, so names are always sent with an address. The phone
 * must come from the checkout; a platform sender ID is never a phone number.
 */
class ShopifyOrderPayload
{
    public static function build(int $variantId, int $quantity, array $checkout): array
    {
        $phone = self::normalizePhone($checkout['phone'] ?? $checkout['customer_phone'] ?? null);
        if ($phone === null) {
            throw new \RuntimeException('shopify_phone_missing');
        }

        [$first, $last] = self::splitName((string) ($checkout['full_name'] ?? ''));
        if ($first === '') {
            throw new \RuntimeException('shopify_name_missing');
        }

        $shipping = self::compact([
            'first_name' => $first,
            'last_name' => $last,
            'address1' => self::nullableTrim($checkout['address'] ?? null),
            'zip' => self::nullableTrim($checkout['postal_code'] ?? null),
            'city' => self::nullableTrim($checkout['city'] ?? null),
            'country' => self::nullableTrim($checkout['country'] ?? null),
            'phone' => $phone,
        ]);

        return [
            'order' => [
                'line_items' => [[
                    'variant_id' => $variantId,
                    'quantity' => max(1, $quantity),
                ]],
                'customer' => self::compact([
                    'first_name' => $first,
                    'last_name' => $last,
                    'phone' => $phone,
                    'email' => self::nullableTrim($checkout['email'] ?? null),
                ]),
                'shipping_address' => $shipping,
            ],
        ];
    }

    /** @return array{0: string, 1: string} */
    public static function splitName(string $fullName): array
    {
        $parts = preg_split('/\s+/', trim($fullName), 2) ?: [];
        $first = $parts[0] ?? '';

        return [$first, $parts[1] ?? $first];
    }

    public static function normalizePhone(mixed $value): ?string
    {
        if (!is_string($value) && !is_int($value)) {
            return null;
        }
        $digits = preg_replace('/[^\d+]/', '', (string) $value) ?? '';
        if (str_starts_with($digits, '00')) {
            $digits = '+' . substr($digits, 2);
        }
        $count = strlen(preg_replace('/\D/', '', $digits) ?? '');

        return $count >= 8 ? $digits : null;
    }

    private static function nullableTrim(mixed $value): ?string
    {
        $text = trim((string) ($value ?? ''));

        return $text === '' ? null : $text;
    }

    private static function compact(array $values): array
    {
        return array_filter($values, fn ($value) => $value !== null && $value !== '');
    }
}