<?php

namespace App\Services;

/**
 * Pre-submit validation for Salla order payloads.
 *
 * Runs before any request is sent to Salla, so missing fields are asked for
 * up front instead of being discovered through a failed 422 round trip.
 */
class SallaOrderValidator
{
    /** Always required for an order, whatever the store has learned. */
    private const BASE_REQUIRED = ['full_name', 'phone', 'address'];

    /** Alternative keys that can hold the same logical value. */
    private const ALIASES = [
        'full_name' => ['full_name'],
        'phone'     => ['phone', 'customer_phone'],
        'email'     => ['email', 'customer_email'],
        'address'   => ['address', 'customer_address'],
    ];

    /**
     * Return the required field names that have no usable value.
     *
     * @param  array $data          Checkout state or order input.
     * @param  array $storeRequired Field names the store has already rejected or required.
     * @return array<int, string>
     */
    public static function missingFields(array $data, array $storeRequired = []): array
    {
        $required = array_values(array_unique(array_merge(self::BASE_REQUIRED, $storeRequired)));
        $missing = [];

        foreach ($required as $field) {
            if (!self::hasValue($data, $field)) {
                $missing[] = $field;
            }
        }

        return $missing;
    }

    private static function hasValue(array $data, string $field): bool
    {
        $keys = self::ALIASES[$field] ?? [$field];

        foreach ($keys as $key) {
            if (self::isFilled($data[$key] ?? null)) {
                return true;
            }
        }

        return false;
    }

    private static function isFilled(mixed $value): bool
    {
        if (is_array($value)) {
            return $value !== [];
        }

        if (is_string($value)) {
            // A customer answer such as "none" is filled: it is later mapped
            // to the store's placeholder value, so it must not be re-asked.
            return trim($value) !== '';
        }

        return $value !== null && $value !== false;
    }
}
