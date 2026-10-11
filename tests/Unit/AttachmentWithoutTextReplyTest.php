<?php

namespace Tests\Unit;

use App\Services\ConfirmationGuard;
use PHPUnit\Framework\TestCase;

class AttachmentWithoutTextReplyTest extends TestCase
{
    public function test_english_reply_asks_for_typed_details_and_never_claims_to_read_the_attachment(): void
    {
        $reply = ConfirmationGuard::attachmentWithoutTextReply(false);
        $this->assertStringContainsString("can't read", $reply);
        $this->assertStringContainsString('type', $reply);
        $this->assertStringNotContainsString('sorry', strtolower($reply));
    }

    public function test_arabic_reply_is_arabic(): void
    {
        $this->assertMatchesRegularExpression('/\p{Arabic}/u', ConfirmationGuard::attachmentWithoutTextReply(true));
    }
}