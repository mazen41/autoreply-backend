<?php

namespace Tests\Unit;

use App\Services\OrderCheckoutService;
use PHPUnit\Framework\TestCase;

class OrderCheckoutPhoneTest extends TestCase
{
    public function test_real_phone_numbers_are_accepted(): void
    {
        $this->assertTrue(OrderCheckoutService::isValidPhone('+201001234567'));
        $this->assertTrue(OrderCheckoutService::isValidPhone('01001234567'));
        $this->assertTrue(OrderCheckoutService::isValidPhone('00966501234567'));
        $this->assertTrue(OrderCheckoutService::isValidPhone('+966 50 123 4567'));
    }

    public function test_placeholder_and_platform_ids_are_rejected(): void
    {
        $this->assertFalse(OrderCheckoutService::isValidPhone('[phone]'));
        $this->assertFalse(OrderCheckoutService::isValidPhone('17841410955426499')); // Instagram user ID
        $this->assertFalse(OrderCheckoutService::isValidPhone('1300174996507145'));  // Facebook page ID with no phone prefix
        $this->assertFalse(OrderCheckoutService::isValidPhone('36'));
        $this->assertFalse(OrderCheckoutService::isValidPhone(null));
        $this->assertFalse(OrderCheckoutService::isValidPhone(['+201001234567']));
    }

    public function test_sanitize_removes_only_invalid_stored_phones(): void
    {
        $clean = OrderCheckoutService::sanitizeStoredPhone([
            'phone' => '[phone]',
            'customer_phone' => '17841410955426499',
            'address' => '36 Sayed Abdelrahman, Giza',
            'full_name' => 'Mazen Hossny Ahmed',
        ]);

        $this->assertArrayNotHasKey('phone', $clean);
        $this->assertArrayNotHasKey('customer_phone', $clean);
        $this->assertSame('36 Sayed Abdelrahman, Giza', $clean['address']);
        $this->assertSame('Mazen Hossny Ahmed', $clean['full_name']);

        $kept = OrderCheckoutService::sanitizeStoredPhone(['phone' => '+201001234567']);
        $this->assertSame('+201001234567', $kept['phone']);
    }
}