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
    public static function build(int $variantId, int $quantity, array $checkout, ?int $existingCustomerId = null, bool $createCustomer = true): array
    {
        $phone = self::normalizePhone(
            $checkout['phone'] ?? $checkout['customer_phone'] ?? null,
            $checkout['country'] ?? $checkout['address'] ?? null
        );
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
            'address1' => self::nullableTrim($checkout['address'] ?? null)
                ?? self::nullableTrim($checkout['customer_address'] ?? null),
            'zip' => self::nullableTrim($checkout['postal_code'] ?? null),
            'city' => self::nullableTrim($checkout['city'] ?? null),
            'country' => self::nullableTrim($checkout['country'] ?? null),
            'phone' => $phone,
        ]);

        $customer = $existingCustomerId !== null
            ? ['id' => $existingCustomerId]
            : ($createCustomer ? self::compact([
                'first_name' => $first,
                'last_name' => $last,
                'phone' => $phone,
                'email' => self::nullableTrim($checkout['email'] ?? null),
            ]) : null);

        $order = [
            'order' => [
                'line_items' => [[
                    'variant_id' => $variantId,
                    'quantity' => max(1, $quantity),
                ]],
                'shipping_address' => $shipping,
            ],
        ];

        if ($customer !== null) {
            $order['order']['customer'] = $customer;
        }

        return $order;
    }

    /** @return array{0: string, 1: string} */
    public static function splitName(string $fullName): array
    {
        $parts = preg_split('/\s+/', trim($fullName), 2) ?: [];
        $first = $parts[0] ?? '';

        return [$first, $parts[1] ?? $first];
    }

    public static function normalizePhone(mixed $value, mixed $countryHint = null): ?string
    {
        if (!is_string($value) && !is_int($value)) {
            return null;
        }
        $raw = trim((string) $value);
        $digits = preg_replace('/\D+/', '', $raw) ?? '';
        if ($digits === '') {
            return null;
        }

        if (str_starts_with($raw, '+')) {
            $international = '+' . $digits;
        } elseif (str_starts_with($digits, '00')) {
            $international = '+' . substr($digits, 2);
        } elseif (preg_match('/^01[0125]\d{8}$/', $digits)) {
            // Egyptian mobile in national format, e.g. 01152879755.
            $international = '+20' . substr($digits, 1);
        } elseif (preg_match('/^05\d{8}$/', $digits)) {
            // Saudi mobile in national format.
            $international = '+966' . substr($digits, 1);
        } else {
            $countryDialCodes = [
                '20' => ['egypt', 'eg', 'مصر'],
                '966' => ['saudi arabia', 'saudi', 'ksa', 'sa', 'السعودية'],
                '971' => ['united arab emirates', 'uae', 'emirates', 'ae', 'الإمارات'],
                '965' => ['kuwait', 'kw', 'الكويت'],
                '974' => ['qatar', 'qa', 'قطر'],
                '973' => ['bahrain', 'bh', 'البحرين'],
                '968' => ['oman', 'om', 'عمان'],
                '967' => ['yemen', 'ye', 'اليمن'],
                '964' => ['iraq', 'iq', 'العراق'],
                '963' => ['syria', 'sy', 'سوريا'],
                '962' => ['jordan', 'jo', 'الأردن'],
                '961' => ['lebanon', 'lb', 'لبنان'],
                '970' => ['palestine', 'ps', 'فلسطين'],
                '218' => ['libya', 'ly', 'ليبيا'],
                '216' => ['tunisia', 'tn', 'تونس'],
                '213' => ['algeria', 'dz', 'الجزائر'],
                '212' => ['morocco', 'ma', 'المغرب'],
                '249' => ['sudan', 'sd', 'السودان'],
                '252' => ['somalia', 'so', 'الصومال'],
                '253' => ['djibouti', 'dj', 'جيبوتي'],
                '269' => ['comoros', 'km', 'جزر القمر'],
                '222' => ['mauritania', 'mr', 'موريتانيا'],
            ];
            $hint = mb_strtolower(trim((string) $countryHint));
            $dialCode = null;
            foreach ($countryDialCodes as $code => $aliases) {
                if (str_starts_with($digits, $code)) {
                    $dialCode = $code;
                    break;
                }
                foreach ($aliases as $alias) {
                    if ($hint !== '' && mb_stripos($hint, mb_strtolower($alias)) !== false) {
                        $dialCode = $code;
                        break 2;
                    }
                }
            }

            if ($dialCode !== null && str_starts_with($digits, '0') && $hint !== '') {
                $international = '+' . $dialCode . substr($digits, 1);
            } elseif ($dialCode !== null && str_starts_with($digits, $dialCode)) {
                $international = '+' . $digits;
            } else {
                return null;
            }
        }

        return preg_match('/^\+[1-9]\d{7,14}$/', $international) ? $international : null;
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
