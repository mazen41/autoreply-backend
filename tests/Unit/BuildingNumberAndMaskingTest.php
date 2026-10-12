<?php

namespace Tests\Unit;

use App\Services\OrderCheckoutService;
use PHPUnit\Framework\TestCase;

class BuildingNumberAndMaskingTest extends TestCase
{
    public function test_leading_number_of_a_street_address_is_the_building_number(): void
    {
        $this->assertSame('36', OrderCheckoutService::extractBuildingNumber('36 sayed abdelrahman aladwey giza fisal'));
        $this->assertSame('36', OrderCheckoutService::extractBuildingNumber('the address is 36 sayed abdelrahman aladwey giza fisal and postal code is 11511'));
    }

    public function test_labelled_building_numbers_are_extracted(): void
    {
        $this->assertSame('12', OrderCheckoutService::extractBuildingNumber('villa 12, Cairo'));
        $this->assertSame('4', OrderCheckoutService::extractBuildingNumber('building no. 4 nasr city'));
        $this->assertSame('7', OrderCheckoutService::extractBuildingNumber('apt 7 maadi'));
    }

    public function test_arabic_building_numbers_are_extracted(): void
    {
        $this->assertSame('15', OrderCheckoutService::extractBuildingNumber('عمارة 15 شارع التحرير'));
        $this->assertSame('8', OrderCheckoutService::extractBuildingNumber('مبنى 8 الجيزة'));
    }

    public function test_postal_codes_and_phone_numbers_are_never_building_numbers(): void
    {
        $this->assertNull(OrderCheckoutService::extractBuildingNumber('postal code is 11511 giza'));
        $this->assertNull(OrderCheckoutService::extractBuildingNumber('my number 01001234567 mazen'));
        $this->assertNull(OrderCheckoutService::extractBuildingNumber(''));
    }

    public function test_emails_and_phone_numbers_are_masked_for_logs(): void
    {
        $masked = OrderCheckoutService::maskSensitive('failed for sara@example.test and 01001234567');
        $this->assertStringNotContainsString('sara@example.test', $masked);
        $this->assertStringNotContainsString('01001234567', $masked);
        $this->assertStringContainsString('[email]', $masked);
        $this->assertStringContainsString('[number]', $masked);
    }
}