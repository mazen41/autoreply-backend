<?php

namespace Tests\Unit;

use App\Services\OrderCheckoutService;
use PHPUnit\Framework\TestCase;

class ExplicitOrderConfirmationTest extends TestCase
{
    public function test_confirmation_phrases_are_recognised(): void
    {
        foreach (['confirm order', 'Yes, please confirm', 'yes confirm', 'yes please', 'please confirm', 'confirm it', 'Yes.'] as $phrase) {
            $this->assertTrue(OrderCheckoutService::isExplicitOrderConfirmation($phrase), $phrase);
        }
    }

    public function test_questions_and_details_are_not_confirmations(): void
    {
        foreach (['how much is it', 'do you deliver to giza', 'my phone is 01001234567', 'I want to confirm my address is 36 sayed street'] as $phrase) {
            $this->assertFalse(OrderCheckoutService::isExplicitOrderConfirmation($phrase), $phrase);
        }
    }
}