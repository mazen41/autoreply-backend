<?php

namespace App\Helpers;

/**
 * Stateless helper for normalizing and validating phone numbers
 * for the Salla order-creation API.
 *
 * Salla Technical Support confirmed:
 * - receiver.phone: full country code WITHOUT leading '+' (e.g. 9665XXXXXXXX)
 * - receiver.country_code: ISO 2-letter code (e.g. SA)
 * - customer.mobile: separate field, MAY include leading '+'
 * - Saudi mobile: 966 + 5XXXXXXXX (9 national digits starting with 5)
 */
class SallaPhoneHelper
{
    /**
     * Known calling-code-to-ISO mappings.
     * Ordered longest-first within each length group to avoid prefix ambiguity.
     */
    private const CALLING_CODES = [
        '966' => ['code' => '+966', 'iso' => 'SA'],
        '971' => ['code' => '+971', 'iso' => 'AE'],
        '965' => ['code' => '+965', 'iso' => 'KW'],
        '974' => ['code' => '+974', 'iso' => 'QA'],
        '973' => ['code' => '+973', 'iso' => 'BH'],
        '968' => ['code' => '+968', 'iso' => 'OM'],
        '967' => ['code' => '+967', 'iso' => 'YE'],
        '962' => ['code' => '+962', 'iso' => 'JO'],
        '961' => ['code' => '+961', 'iso' => 'LB'],
        '964' => ['code' => '+964', 'iso' => 'IQ'],
        '20'  => ['code' => '+20',  'iso' => 'EG'],
        '90'  => ['code' => '+90',  'iso' => 'TR'],
        '91'  => ['code' => '+91',  'iso' => 'IN'],
        '44'  => ['code' => '+44',  'iso' => 'GB'],
        '33'  => ['code' => '+33',  'iso' => 'FR'],
        '49'  => ['code' => '+49',  'iso' => 'DE'],
        '1'   => ['code' => '+1',   'iso' => 'US'],
    ];

    /**
     * Normalize a raw phone string into its calling-code and national-number components.
     *
     * Returns: ['calling_code' => '+966', 'national' => '512345678', 'iso' => 'SA']
     * or null if the phone is missing, malformed, or unrecognizable.
     */
    public static function normalize(string $phone): ?array
    {
        $phone = trim($phone);
        if ($phone === '') {
            return null;
        }

        // Remember if the original input had an explicit international prefix
        $hadExplicitPrefix = str_starts_with($phone, '+') || str_starts_with($phone, '00');

        // Strip everything except digits
        $digits = preg_replace('/[^0-9]/', '', $phone);
        if ($digits === '' || $digits === null) {
            return null;
        }

        // Strip the '00' international dialing prefix from digits
        if (str_starts_with($phone, '00') && str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        }

        // Explicit international prefix (+ or 00)
        if ($hadExplicitPrefix) {
            foreach (self::CALLING_CODES as $prefix => $meta) {
                $prefixStr = (string) $prefix;
                if (str_starts_with($digits, $prefixStr)) {
                    $national = substr($digits, strlen($prefixStr));
                    // Strip accidental trunk '0' after country code (e.g. +966 05XXXXXXXX)
                    if ($prefixStr === '966' && str_starts_with($national, '05') && strlen($national) === 10) {
                        $national = substr($national, 1);
                    }
                    return [
                        'calling_code' => $meta['code'],
                        'national'     => $national,
                        'iso'          => $meta['iso'],
                    ];
                }
            }

            // Unknown international prefix: best-effort 1-3 digit code guess
            $codeLength = strlen($digits) >= 12 ? 3 : (strlen($digits) >= 11 ? 2 : 1);
            return [
                'calling_code' => '+' . substr($digits, 0, $codeLength),
                'national'     => substr($digits, $codeLength),
                'iso'          => null,
            ];
        }

        // No explicit prefix: detect by unambiguous digit pattern

        // Saudi international without +: 9665XXXXXXXX (12 digits)
        if (str_starts_with($digits, '9665') && strlen($digits) === 12) {
            return ['calling_code' => '+966', 'national' => substr($digits, 3), 'iso' => 'SA'];
        }

        // Saudi international with accidental trunk '0': 96605XXXXXXXX (13 digits)
        if (str_starts_with($digits, '96605') && strlen($digits) === 13) {
            return ['calling_code' => '+966', 'national' => substr($digits, 4), 'iso' => 'SA'];
        }

        // Saudi local with trunk prefix: 05XXXXXXXX (10 digits)
        if (preg_match('/^05[0-9]{8}$/', $digits)) {
            return ['calling_code' => '+966', 'national' => substr($digits, 1), 'iso' => 'SA'];
        }

        // Saudi national mobile without trunk: 5XXXXXXXX (9 digits starting with 5)
        if (preg_match('/^5[0-9]{8}$/', $digits)) {
            return ['calling_code' => '+966', 'national' => $digits, 'iso' => 'SA'];
        }

        // Egyptian international without +: 201XXXXXXXXX (12+ digits)
        if (str_starts_with($digits, '201') && strlen($digits) >= 12) {
            return ['calling_code' => '+20', 'national' => substr($digits, 2), 'iso' => 'EG'];
        }

        // Egyptian local: 01XXXXXXXXX (11 digits)
        if (preg_match('/^01[0-9]{9}$/', $digits)) {
            return ['calling_code' => '+20', 'national' => substr($digits, 1), 'iso' => 'EG'];
        }

        // UAE international without +: 9715XXXXXXXX (12 digits)
        if (str_starts_with($digits, '9715') && strlen($digits) === 12) {
            return ['calling_code' => '+971', 'national' => substr($digits, 3), 'iso' => 'AE'];
        }

        // Kuwait international without +: 965XXXXXXXX (11 digits)
        if (str_starts_with($digits, '965') && strlen($digits) === 11) {
            return ['calling_code' => '+965', 'national' => substr($digits, 3), 'iso' => 'KW'];
        }

        // Qatar international without +: 974XXXXXXXX (11 digits)
        if (str_starts_with($digits, '974') && strlen($digits) === 11) {
            return ['calling_code' => '+974', 'national' => substr($digits, 3), 'iso' => 'QA'];
        }

        // Bahrain international without +: 973XXXXXXXX (11 digits)
        if (str_starts_with($digits, '973') && strlen($digits) === 11) {
            return ['calling_code' => '+973', 'national' => substr($digits, 3), 'iso' => 'BH'];
        }

        // Oman international without +: 968XXXXXXXX (11 digits)
        if (str_starts_with($digits, '968') && strlen($digits) === 11) {
            return ['calling_code' => '+968', 'national' => substr($digits, 3), 'iso' => 'OM'];
        }

        // Cannot determine country
        return null;
    }

    /**
     * Format phone for Salla's `receiver.phone` field.
     * Returns the full number WITHOUT leading '+': e.g. '966512345678'
     *
     * @throws \InvalidArgumentException if the phone is invalid or unrecognizable
     */
    public static function formatForReceiver(string $phone): string
    {
        $normalized = self::normalize($phone);
        if ($normalized === null) {
            throw new \InvalidArgumentException(
                'Cannot format receiver phone: the number is missing, malformed, or its country cannot be determined.'
            );
        }

        // Full number without +
        return ltrim($normalized['calling_code'], '+') . $normalized['national'];
    }

    /**
     * Format phone for Salla's `customer.mobile` field.
     * Returns the full number WITH leading '+': e.g. '+966512345678'
     *
     * @throws \InvalidArgumentException if the phone is invalid or unrecognizable
     */
    public static function formatForCustomerMobile(string $phone): string
    {
        $normalized = self::normalize($phone);
        if ($normalized === null) {
            throw new \InvalidArgumentException(
                'Cannot format customer mobile: the number is missing, malformed, or its country cannot be determined.'
            );
        }

        return $normalized['calling_code'] . $normalized['national'];
    }

    /**
     * Get the ISO 2-letter country code for a phone number.
     * Returns null if the country cannot be determined.
     */
    public static function getCountryCode(string $phone): ?string
    {
        $normalized = self::normalize($phone);
        return $normalized['iso'] ?? null;
    }

    /**
     * Validate that a phone number looks like a valid Saudi mobile.
     * Saudi mobile: 966 + 5XXXXXXXX (9 national digits starting with 5, total 12 digits).
     */
    public static function isValidSaudiMobile(string $phone): bool
    {
        $normalized = self::normalize($phone);
        if ($normalized === null || $normalized['iso'] !== 'SA') {
            return false;
        }

        $national = $normalized['national'];

        // Must be exactly 9 digits starting with 5
        if (!preg_match('/^5[0-9]{8}$/', $national)) {
            return false;
        }

        // Reject repeated-digit placeholders (e.g. 555555555, 500000000, 566666666)
        // 1. All same digits
        if (preg_match('/^(.)\1+$/', $national)) {
            return false;
        }
        // 2. 5 followed by 8 identical digits (e.g. 500000000, 566666666)
        if (preg_match('/^5(.)\1{7}$/', $national)) {
            return false;
        }
        // 3. 6 or more consecutive identical digits anywhere
        if (preg_match('/(.)\1{5,}/', $national)) {
            return false;
        }

        return true;
    }

    /**
     * Validate that a phone number has a recognizable format with sufficient digits.
     * This does NOT guarantee the number is reachable or assigned.
     */
    public static function isValidFormat(string $phone): bool
    {
        $normalized = self::normalize($phone);
        if ($normalized === null) {
            return false;
        }

        // If Saudi, enforce full Saudi mobile validation
        if ($normalized['iso'] === 'SA') {
            return self::isValidSaudiMobile($phone);
        }

        $national = $normalized['national'];

        // National number should be between 7 and 13 digits
        if (strlen($national) < 7 || strlen($national) > 13) {
            return false;
        }

        // Reject all-same-digit placeholders
        if (preg_match('/^(.)\1+$/', $national)) {
            return false;
        }

        // Reject 6 or more consecutive identical digits
        if (preg_match('/(.)\1{5,}/', $national)) {
            return false;
        }

        return true;
    }
}
