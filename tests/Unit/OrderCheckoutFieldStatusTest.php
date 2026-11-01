<?php

namespace Tests\Unit;

use App\Models\Conversation;
use App\Services\OrderCheckoutService;
use PHPUnit\Framework\TestCase;

/**
 * Regression tests for the Salla checkout fix:
 *  - National-address extras (postal code, building number, block,
 *    coordinates, district, short address, additional number) must NOT be
 *    universally mandatory; only full_name/phone/address are, unless Salla's
 *    own API has actually told us (via $sallaRequiredFields) that a specific
 *    extra field is required for this store.
 *  - PO Box must be captured as its own field, never as building_number.
 *  - Saudi city ("Hofuf") and postal code must be recognised from a single
 *    free-form message without extra prompting.
 */
class OrderCheckoutFieldStatusTest extends TestCase
{
    public function test_salla_checkout_does_not_require_national_address_extras_by_default(): void
    {
        $service = new OrderCheckoutService();

        $status = $service->computeFieldStatus([
            'full_name' => 'Mazen Hossny',
            'phone' => '+966501234567',
            'address' => 'Ghaali Mitri, Hofuf, Saudi Arabia',
        ], true);

        $this->assertTrue($status['is_complete']);
        $this->assertSame([], $status['missing_fields']);
        $this->assertNotContains('geo_coordinates', $status['required_fields']);
        $this->assertNotContains('short_address', $status['required_fields']);
        $this->assertNotContains('district', $status['required_fields']);
    }

    public function test_salla_reported_required_fields_are_honoured_once_known(): void
    {
        $service = new OrderCheckoutService();

        $status = $service->computeFieldStatus([
            'full_name' => 'Mazen Hossny',
            'phone' => '+966501234567',
            'address' => 'Ghaali Mitri, Hofuf, Saudi Arabia',
        ], true, ['postal_code', 'district']);

        $this->assertFalse($status['is_complete']);
        $this->assertSame(['postal_code', 'district'], $status['missing_fields']);
    }

    public function test_po_box_is_extracted_as_its_own_field_not_building_number(): void
    {
        $service = new OrderCheckoutService();
        $conversation = new Conversation(['checkout_state' => null]);

        $state = $service->extractAndMergeState(
            $conversation,
            'Name: Mazen Hossny, Phone: +966501234567, Address: Ghaali Mitri, P.O. Box 122, Hofuf, Postal Code: 36361, Saudi Arabia',
            ['salla_product_id' => 'prod-1', 'name' => 'Dress', 'price' => 100, 'currency' => 'SAR']
        );

        $this->assertSame('122', $state['po_box']);
        $this->assertArrayNotHasKey('building_number', $state);
        $this->assertSame('36361', $state['postal_code']);
    }

    public function test_full_checkout_message_extracts_name_phone_city_and_postal_code(): void
    {
        $service = new OrderCheckoutService();
        $conversation = new Conversation(['checkout_state' => null]);

        $state = $service->extractAndMergeState(
            $conversation,
            'My name is Mazen Hossny, phone +966501234567, address Ghaali Mitri, P.O. Box 122, Hofuf, Postal Code: 36361, Saudi Arabia',
            ['salla_product_id' => 'prod-1', 'name' => 'Dress', 'price' => 100, 'currency' => 'SAR']
        );

        $this->assertSame('Mazen Hossny', $state['full_name']);
        $this->assertSame('+966501234567', $state['phone']);
        $this->assertSame('36361', $state['postal_code']);
        $this->assertSame('122', $state['po_box']);
        $this->assertStringContainsString('Hofuf', $state['address']);
    }

    public function test_fields_accumulate_across_separate_messages_without_losing_prior_data(): void
    {
        $service = new OrderCheckoutService();

        $conversation = new Conversation(['checkout_state' => null]);
        $afterFirst = $service->extractAndMergeState(
            $conversation,
            'My name is Mazen Hossny and phone +966501234567',
            ['salla_product_id' => 'prod-1', 'name' => 'Dress', 'price' => 100, 'currency' => 'SAR']
        );
        $this->assertSame('Mazen Hossny', $afterFirst['full_name']);
        $this->assertSame('+966501234567', $afterFirst['phone']);
        $this->assertArrayNotHasKey('address', $afterFirst);

        $conversation2 = new Conversation(['checkout_state' => $afterFirst]);
        $afterSecond = $service->extractAndMergeState(
            $conversation2,
            'My address is Ghaali Mitri, Hofuf, Saudi Arabia',
            null
        );

        // Name and phone from the first message must survive the second merge.
        $this->assertSame('Mazen Hossny', $afterSecond['full_name']);
        $this->assertSame('+966501234567', $afterSecond['phone']);
        $this->assertStringContainsString('Hofuf', $afterSecond['address']);
    }
}
