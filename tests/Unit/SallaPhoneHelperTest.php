<?php

namespace Tests\Unit;

use App\Helpers\SallaPhoneHelper;
use PHPUnit\Framework\TestCase;

class SallaPhoneHelperTest extends TestCase
{
    /**
     * Requirement 1: A valid Saudi local mobile number is normalized correctly
     * when the conversion is unambiguous.
     */
    public function test_valid_saudi_local_mobile_is_normalized_correctly(): void
    {
        // 10-digit format starting with 05
        $norm1 = SallaPhoneHelper::normalize('0558912437');
        $this->assertNotNull($norm1);
        $this->assertSame('+966', $norm1['calling_code']);
        $this->assertSame('558912437', $norm1['national']);
        $this->assertSame('SA', $norm1['iso']);
        $this->assertSame('966558912437', SallaPhoneHelper::formatForReceiver('0558912437'));
        $this->assertSame('+966558912437', SallaPhoneHelper::formatForCustomerMobile('0558912437'));
        $this->assertTrue(SallaPhoneHelper::isValidSaudiMobile('0558912437'));

        // 9-digit format starting with 5 (without leading 0)
        $norm2 = SallaPhoneHelper::normalize('558912437');
        $this->assertNotNull($norm2);
        $this->assertSame('+966', $norm2['calling_code']);
        $this->assertSame('558912437', $norm2['national']);
        $this->assertSame('SA', $norm2['iso']);
        $this->assertSame('966558912437', SallaPhoneHelper::formatForReceiver('558912437'));
        $this->assertSame('+966558912437', SallaPhoneHelper::formatForCustomerMobile('558912437'));
        $this->assertTrue(SallaPhoneHelper::isValidSaudiMobile('558912437'));
    }

    /**
     * Requirement 2: A valid international number beginning with +966
     * produces receiver.phone without +.
     */
    public function test_valid_international_number_produces_receiver_phone_without_plus(): void
    {
        $phone = '+966558912437';
        $receiverPhone = SallaPhoneHelper::formatForReceiver($phone);

        $this->assertSame('966558912437', $receiverPhone);
        $this->assertStringStartsNotWith('+', $receiverPhone);
        $this->assertSame(12, strlen($receiverPhone));
    }

    /**
     * Requirement 3: An already-normalized 9665XXXXXXXX number does not receive
     * a duplicate country code.
     */
    public function test_already_normalized_number_does_not_receive_duplicate_country_code(): void
    {
        $alreadyNormalized = '966558912437';
        $receiverPhone = SallaPhoneHelper::formatForReceiver($alreadyNormalized);

        $this->assertSame('966558912437', $receiverPhone);
        $this->assertStringNotContainsString('966966', $receiverPhone);
        $this->assertSame(12, strlen($receiverPhone));
    }

    /**
     * Requirement 4: receiver.country_code is "SA" for a Saudi recipient,
     * and non-Saudi markets preserve their respective country code.
     */
    public function test_receiver_country_code_resolution(): void
    {
        // Saudi recipients
        $this->assertSame('SA', SallaPhoneHelper::getCountryCode('+966558912437'));
        $this->assertSame('SA', SallaPhoneHelper::getCountryCode('966558912437'));
        $this->assertSame('SA', SallaPhoneHelper::getCountryCode('0558912437'));
        $this->assertSame('SA', SallaPhoneHelper::getCountryCode('558912437'));

        // Non-Saudi recipients must preserve their country code (not forced to SA)
        $this->assertSame('EG', SallaPhoneHelper::getCountryCode('+201001234567'));
        $this->assertSame('EG', SallaPhoneHelper::getCountryCode('01001234567'));
        $this->assertSame('AE', SallaPhoneHelper::getCountryCode('+971501234567'));
        $this->assertSame('KW', SallaPhoneHelper::getCountryCode('+96591234567'));
        $this->assertSame('QA', SallaPhoneHelper::getCountryCode('+97455123456'));
        $this->assertSame('BH', SallaPhoneHelper::getCountryCode('+97339123456'));
        $this->assertSame('OM', SallaPhoneHelper::getCountryCode('+96891234567'));
        $this->assertSame('JO', SallaPhoneHelper::getCountryCode('+962791234567'));
        $this->assertSame('US', SallaPhoneHelper::getCountryCode('+14155552671'));
        $this->assertSame('GB', SallaPhoneHelper::getCountryCode('+447911123456'));
    }

    /**
     * Requirement 5: customer.mobile retains the format expected by its separate API field (+).
     */
    public function test_customer_mobile_retains_leading_plus(): void
    {
        $this->assertSame('+966558912437', SallaPhoneHelper::formatForCustomerMobile('0558912437'));
        $this->assertSame('+966558912437', SallaPhoneHelper::formatForCustomerMobile('+966558912437'));
        $this->assertSame('+966558912437', SallaPhoneHelper::formatForCustomerMobile('966558912437'));
        $this->assertSame('+966558912437', SallaPhoneHelper::formatForCustomerMobile('558912437'));
        $this->assertSame('+201001234567', SallaPhoneHelper::formatForCustomerMobile('01001234567'));
    }

    /**
     * Requirement 6: Invalid-length numbers are rejected.
     */
    public function test_invalid_length_numbers_are_rejected(): void
    {
        // Too short for Saudi (8 national digits)
        $this->assertFalse(SallaPhoneHelper::isValidSaudiMobile('055891243'));
        $this->assertFalse(SallaPhoneHelper::isValidSaudiMobile('+96655891243'));
        $this->assertFalse(SallaPhoneHelper::isValidSaudiMobile('55891243'));

        // Too long for Saudi (10 national digits)
        $this->assertFalse(SallaPhoneHelper::isValidSaudiMobile('05589124371'));
        $this->assertFalse(SallaPhoneHelper::isValidSaudiMobile('+9665589124371'));
        $this->assertFalse(SallaPhoneHelper::isValidSaudiMobile('5589124371'));

        // Overall too short (less than 7 digits)
        $this->assertFalse(SallaPhoneHelper::isValidFormat('12345'));
        $this->assertFalse(SallaPhoneHelper::isValidFormat('36'));
    }

    /**
     * Requirement 7: Repeated-digit placeholder numbers are not accepted as valid test numbers.
     */
    public function test_repeated_digit_placeholders_are_rejected(): void
    {
        // Salla documentation placeholder: 566666666 (5 followed by eight 6s)
        $this->assertFalse(SallaPhoneHelper::isValidSaudiMobile('+966566666666'));
        $this->assertFalse(SallaPhoneHelper::isValidSaudiMobile('966566666666'));
        $this->assertFalse(SallaPhoneHelper::isValidSaudiMobile('0566666666'));
        $this->assertFalse(SallaPhoneHelper::isValidSaudiMobile('566666666'));
        $this->assertFalse(SallaPhoneHelper::isValidFormat('+966566666666'));

        // All zero sequence: 500000000 (5 followed by eight 0s)
        $this->assertFalse(SallaPhoneHelper::isValidSaudiMobile('+966500000000'));
        $this->assertFalse(SallaPhoneHelper::isValidSaudiMobile('966500000000'));
        $this->assertFalse(SallaPhoneHelper::isValidSaudiMobile('0500000000'));
        $this->assertFalse(SallaPhoneHelper::isValidSaudiMobile('500000000'));
        $this->assertFalse(SallaPhoneHelper::isValidFormat('+966500000000'));

        // All same digit: 555555555 (all 5s)
        $this->assertFalse(SallaPhoneHelper::isValidSaudiMobile('+966555555555'));
        $this->assertFalse(SallaPhoneHelper::isValidSaudiMobile('0555555555'));
        $this->assertFalse(SallaPhoneHelper::isValidSaudiMobile('555555555'));
        $this->assertFalse(SallaPhoneHelper::isValidFormat('555555555'));

        // Realistic non-repeating number is accepted
        $this->assertTrue(SallaPhoneHelper::isValidSaudiMobile('+966558912437'));
        $this->assertTrue(SallaPhoneHelper::isValidSaudiMobile('966558912437'));
        $this->assertTrue(SallaPhoneHelper::isValidSaudiMobile('0558912437'));
        $this->assertTrue(SallaPhoneHelper::isValidSaudiMobile('558912437'));
        $this->assertTrue(SallaPhoneHelper::isValidFormat('+966558912437'));
    }

    /**
     * Requirement 8: Missing or malformed numbers produce a clear validation error.
     */
    public function test_missing_or_malformed_numbers_produce_clear_error(): void
    {
        $this->assertNull(SallaPhoneHelper::normalize(''));
        $this->assertNull(SallaPhoneHelper::normalize('   '));
        $this->assertNull(SallaPhoneHelper::normalize('[phone]'));
        $this->assertNull(SallaPhoneHelper::normalize('not-a-number'));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Cannot format receiver phone');
        SallaPhoneHelper::formatForReceiver('');
    }

    public function test_malformed_customer_mobile_throws_exception(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Cannot format customer mobile');
        SallaPhoneHelper::formatForCustomerMobile('invalid_phone');
    }

    /**
     * Common input variations: spaces, hyphens, parentheses, and leading 00.
     */
    public function test_input_variations_are_handled_correctly(): void
    {
        $variations = [
            '+966 55 891 2437',
            '+966-55-891-2437',
            '+966 (55) 891 2437',
            '00966 55 891 2437',
            '00966558912437',
            ' 055 891 2437 ',
            '055-891-2437',
            '(055) 891-2437',
        ];

        foreach ($variations as $var) {
            $this->assertSame(
                '966558912437',
                SallaPhoneHelper::formatForReceiver($var),
                "Failed formatting receiver phone for variation: {$var}"
            );
            $this->assertSame(
                '+966558912437',
                SallaPhoneHelper::formatForCustomerMobile($var),
                "Failed formatting customer mobile for variation: {$var}"
            );
            $this->assertSame(
                'SA',
                SallaPhoneHelper::getCountryCode($var),
                "Failed getting country code for variation: {$var}"
            );
        }
    }

    /**
     * Accidental trunk '0' after country code (e.g. +966 05XXXXXXXX) is stripped cleanly.
     */
    public function test_accidental_trunk_zero_after_country_code_is_stripped(): void
    {
        $withTrunk = '+966 0558912437';
        $this->assertSame('966558912437', SallaPhoneHelper::formatForReceiver($withTrunk));
        $this->assertSame('+966558912437', SallaPhoneHelper::formatForCustomerMobile($withTrunk));

        $unprefixedWithTrunk = '9660558912437';
        $this->assertSame('966558912437', SallaPhoneHelper::formatForReceiver($unprefixedWithTrunk));
        $this->assertSame('+966558912437', SallaPhoneHelper::formatForCustomerMobile($unprefixedWithTrunk));
    }
}
