<?php

namespace Tests\Unit;

use App\Services\ConfirmationGuard;
use PHPUnit\Framework\TestCase;

class ConfirmationGuardTest extends TestCase
{
    public function test_model_yes_request_is_replaced_while_a_summary_is_pending(): void
    {
        $reply = 'Would you like me to confirm and submit this order now? Please reply YES to confirm!';
        $this->assertSame(
            ConfirmationGuard::CONFIRM_INSTRUCTION,
            ConfirmationGuard::replaceModelConfirmation($reply, 'confirmation_pending', false)
        );
    }

    public function test_deterministic_summary_is_never_replaced(): void
    {
        $summary = "Please review your order summary:\nProduct: X\nReply \"confirm order\" to place it.";
        $this->assertSame($summary, ConfirmationGuard::replaceModelConfirmation($summary, 'confirmation_pending', false));
    }

    public function test_replies_are_untouched_outside_the_pending_state_or_on_confirmation(): void
    {
        $reply = 'Please reply YES to confirm.';
        $this->assertSame($reply, ConfirmationGuard::replaceModelConfirmation($reply, null, false));
        $this->assertSame($reply, ConfirmationGuard::replaceModelConfirmation($reply, 'confirmation_pending', true));
    }

    public function test_placeholder_phone_asks_for_the_real_number(): void
    {
        $this->assertNotNull(ConfirmationGuard::placeholderPhoneReply('mazen and the phone number is [phone] and the address is 36 giza'));
        $this->assertNull(ConfirmationGuard::placeholderPhoneReply('my phone is 01001234567'));
    }
}