<?php

namespace Tests\Unit;

use App\Services\ShopifyOrderIntent;
use PHPUnit\Framework\TestCase;

class ShopifyOrderIntentTest extends TestCase
{
    public function test_clear_ordering_phrases_are_recognised(): void
    {
        $this->assertTrue(ShopifyOrderIntent::wantsToOrder('i wanna place this order please'));
        $this->assertTrue(ShopifyOrderIntent::wantsToOrder('I want to buy it'));
        $this->assertTrue(ShopifyOrderIntent::wantsToOrder('can I checkout now'));
        $this->assertTrue(ShopifyOrderIntent::wantsToOrder('عايز اطلب ده'));
        $this->assertTrue(ShopifyOrderIntent::wantsToOrder('هاخده'));
    }

    public function test_browsing_and_detail_messages_are_not_ordering(): void
    {
        $this->assertFalse(ShopifyOrderIntent::wantsToOrder('i wanna see your products please'));
        $this->assertFalse(ShopifyOrderIntent::wantsToOrder('images'));
        $this->assertFalse(ShopifyOrderIntent::wantsToOrder('what is the price of this'));
        $this->assertFalse(ShopifyOrderIntent::wantsToOrder('mazen hossny ahmed and the phone is 01001234567 and the address is 36 sayed abdelrahman giza'));
        $this->assertFalse(ShopifyOrderIntent::wantsToOrder('1'));
        $this->assertFalse(ShopifyOrderIntent::wantsToOrder(''));
    }
}