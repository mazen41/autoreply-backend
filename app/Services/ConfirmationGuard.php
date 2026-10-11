<?php

namespace App\Services;

/**
 * Keeps the customer's confirmation path consistent with the order summary.
 * The model may not ask for a "YES" of its own while a summary is pending,
 * and a placeholder phone is never silently accepted.
 */
class ConfirmationGuard
{
    public const CONFIRM_INSTRUCTION = 'Your order summary is above. Reply "confirm order" to place it, or "cancel" to stop.';

    public static function replaceModelConfirmation(string $reply, ?string $confirmationState, bool $customerConfirmed): string
    {
        if ($confirmationState !== 'confirmation_pending' || $customerConfirmed) {
            return $reply;
        }

        // Our own deterministic summary is left alone.
        if (stripos($reply, 'order summary') !== false) {
            return $reply;
        }

        if (preg_match('/\b(?:yes|confirm|submit)\b/i', $reply) === 1) {
            return self::CONFIRM_INSTRUCTION;
        }

        return $reply;
    }

    public static function placeholderPhoneReply(string $customerText): ?string
    {
        if (preg_match('/\[\s*phone\s*\]/i', $customerText) === 1) {
            return 'Please send your phone number (digits only, with country code if you can) so I can complete your order summary.';
        }

        return null;
    }

    /** Reply for an attachment the bot cannot read (no text, no media stored). */
    public static function attachmentWithoutTextReply(bool $arabic): string
    {
        return $arabic
            ? 'وصلتني الصورة أو المرفق لكن مش قادر أقرأه. اكتبلي طلبك أو التفاصيل اللي محتاجها من فضلك.'
            : "I received your attachment, but I can't read it. Please type your request or the details you need.";
    }
}