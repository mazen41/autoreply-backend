<?php

namespace Tests\Unit;

use App\Services\ShopifyOrderPayload;
use PHPUnit\Framework\TestCase;

class ShopifyOrderPayloadTest extends TestCase
{
    private function checkout(array $overrides = []): array
    {
        return array_merge([
            'full_name' => 'Sara Hassan',
            'phone' => '+201001234567',
            'address' => '12 Tahrir Street',
            'postal_code' => '11511',
            'city' => 'Cairo',
            'country' => 'Egypt',
            'email' => 'sara@example.test',
        ], $overrides);
    }

    public function test_shipping_address_always_carries_both_names(): void
    {
        $body = ShopifyOrderPayload::build(555, 2, $this->checkout());

        $shipping = $body['order']['shipping_address'];
        $this->assertSame('Sara', $shipping['first_name']);
        $this->assertSame('Hassan', $shipping['last_name']);
        $this->assertSame('12 Tahrir Street', $shipping['address1']);
        $this->assertSame('11511', $shipping['zip']);
        $this->assertSame('Cairo', $shipping['city']);
        $this->assertSame('Egypt', $shipping['country']);
    }

    public function test_single_word_name_is_sent_as_both_names(): void
    {
        $body = ShopifyOrderPayload::build(555, 1, $this->checkout(['full_name' => 'Sara']));

        $this->assertSame('Sara', $body['order']['shipping_address']['first_name']);
        $this->assertSame('Sara', $body['order']['shipping_address']['last_name']);
    }

    public function test_phone_is_taken_from_the_checkout_only(): void
    {
        $body = ShopifyOrderPayload::build(555, 1, $this->checkout());

        $this->assertSame('+201001234567', $body['order']['customer']['phone']);
        $this->assertSame('+201001234567', $body['order']['shipping_address']['phone']);
    }

    public function test_missing_phone_throws_instead_of_using_a_platform_id(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('shopify_phone_missing');

        // A Messenger-style sender ID is not a phone number and must never be used.
        ShopifyOrderPayload::build(555, 1, $this->checkout(['phone' => null]));
    }

    public function test_too_short_phone_is_rejected(): void
    {
        $this->assertNull(ShopifyOrderPayload::normalizePhone('12345'));
    }

    public function test_double_zero_prefix_becomes_plus(): void
    {
        $this->assertSame('+201001234567', ShopifyOrderPayload::normalizePhone('00201001234567'));
    }

    public function test_egyptian_local_mobile_is_converted_to_international_format(): void
    {
        $this->assertSame('+201152879755', ShopifyOrderPayload::normalizePhone('01152879755'));
    }

    public function test_saudi_local_mobile_is_converted_to_international_format(): void
    {
        $this->assertSame('+966551234567', ShopifyOrderPayload::normalizePhone('0551234567'));
    }

    public function test_optional_address_parts_are_omitted_when_unknown(): void
    {
        $body = ShopifyOrderPayload::build(555, 1, $this->checkout(['postal_code' => null, 'city' => null, 'country' => null]));

        $this->assertArrayNotHasKey('zip', $body['order']['shipping_address']);
        $this->assertArrayNotHasKey('city', $body['order']['shipping_address']);
        $this->assertArrayNotHasKey('country', $body['order']['shipping_address']);
        $this->assertSame('12 Tahrir Street', $body['order']['shipping_address']['address1']);
    }
}
