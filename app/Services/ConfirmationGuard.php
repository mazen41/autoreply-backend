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

    /**
     * Prevent the model from claiming a completed action (order placed, booking
     * confirmed, refund issued, etc.) unless a real backend action succeeded in
     * this same turn.
     *
     * @param string   $reply          The model's reply before sending.
     * @param bool     $hasRealOrderId   True only when this turn received a provider order ID.
     * @param string   $language       'arabic' or anything else for English.
     * @param string[] $successfulActions Action types that completed in this turn.
     * @return string  Original reply, or a safe fallback.
     */
    public static function blockUngroundedActionClaim(
        string $reply,
        bool $hasRealOrderId,
        string $language = 'other',
        array $successfulActions = []
    ): string {
        $lower = mb_strtolower($reply);

        $claimTypes = [
            'order' => '/(?:\border\s*(?:number|no\.?|id)\s*(?:is|:)?\s*#?\d{5,}|awaiting review|waiting for review|\b(?:order|purchase)\b[^.!?\n]{0,70}\b(?:placed|created|submitted|confirmed|processed|completed|done)\b|\b(?:placed|created|submitted)\b[^.!?\n]{0,30}\border\b|تم\s*(?:تأكيد|إنشاء|تسجيل|استلام|تجهيز|إتمام)\s*(?:ال)?(?:طلب|الطلب)?|(?:طلبك|الطلب)\s*(?:اتأكد|تأكد|اتسجل|اتعمل|تم))/u',
            // Only match an assertion that cancellation is already complete.
            // Requests, offers, and instructions containing “cancel” are not claims.
            'cancel' => '/(?:\b(?:(?:your|the)\s+)?(?:order|booking|appointment|subscription)\s+(?:(?:has\s+been|has\s+now\s+been|was|is(?:\s+now)?)\s+cancel(?:led|ed))\b|\b(?:cancelled|canceled)\s+(?:(?:your|the)\s+)?(?:order|booking|appointment|subscription)\b|\bi\s+(?:have\s+)?cancelled\s+(?:(?:your|the)\s+)?(?:order|booking|appointment|subscription)\b|تم\s*(?:إلغاء|الغاء)\s*(?:ال)?(?:طلب|حجز|موعد)|(?:اتلغى|أُلغي)\s*(?:ال)?(?:طلب|حجز|موعد))/u',
            'refund' => '/(?:\b(?:refund|refunded|money back)\b|تم\s*(?:استرداد|رد)\s*(?:المبلغ|الفلوس)?)/u',
            // Match explicit completed changes, not questions/prompts about updating.
            'update' => '/(?:\b(?:(?:your|the)\s+)?(?:order|address|account|profile|record|details)\s+(?:has\s+been|have\s+been|was|were)\s+(?:updated|changed|saved)\b|\b(?:i\s+(?:have\s+)?|we\s+(?:have\s+)?)?(?:updated|changed|saved)\s+(?:(?:your|the)\s+)?(?:order|address|account|profile|record|details)\b|تم\s*(?:تحديث|تعديل|حفظ)\s*(?:بيانات|عنوان|حساب|ملف)(?:ك|ك\s+)?)/u',
            'booking' => '/(?:\b(?:booking|appointment|reservation)\b[^.!?\n]{0,50}\b(?:booked|confirmed|created|scheduled|done|complete)\b|\b(?:booked|confirmed|scheduled)\b[^.!?\n]{0,30}\b(?:appointment|reservation)\b|تم\s*(?:تأكيد|إنشاء|حجز)\s*(?:الموعد|الحجز))/u',
            'create' => '/(?:\b(?:i(?:\s+have|\x27ve)?\s+)?(?:successfully\s+)?created\s+(?:it|that|this|your|the)\b|\bcreated\s+successfully\b|تم\s*(?:إنشاء|تجهيز|إعداد)\s*(?:لك|بنـجاح)?)/u',
            'confirm' => '/(?:\b(?:is|has been|was)\s+confirmed\b|\bconfirmed\s+(?:successfully|for you|it|that)\b|تم\s*تأكيد\b)/u',
            'generic' => '/(?:\b(?:successfully completed|has been completed|is now complete|done successfully|all done|that\x27s done|it\x27s done)\b|تم\s*(?:بنجاح|اكتمال))/u',
        ];

        foreach ($claimTypes as $action => $pattern) {
            if (!preg_match($pattern, $lower)) continue;

            $grounded = $action === 'order' && $hasRealOrderId;
            $matchingAction = match ($action) {
                'order' => ['create_order', 'place_order'],
                'cancel' => ['cancel_order', 'cancel_booking'],
                'refund' => ['refund_order', 'issue_refund'],
                'update' => ['update_customer', 'update_address', 'update_order'],
                'booking' => ['book_appointment', 'create_booking'],
                'create' => ['create_order', 'create_booking', 'create_ticket', 'create_record'],
                'confirm' => ['confirm_order', 'confirm_booking', 'book_appointment'],
                'generic' => [],
                default => [],
            };
            $grounded = $grounded || (bool) array_intersect($matchingAction, $successfulActions);
            if ($grounded) continue;

            \Illuminate\Support\Facades\Log::warning(
                'ConfirmationGuard: blocked ungrounded action claim in model reply',
                ['claim_type' => $action]
            );
            return $language === 'arabic'
                ? 'عذراً، لم أتمكن من إتمام الإجراء بعد. لم يتم تغيير أي شيء. أقدر أساعدك تجرّب مرة تانية أو أوصلك بفريق الدعم.'
                : 'Sorry, I could not complete that action yet, so nothing has been changed. I can help you try again or connect you with the support team.';
        }

        return $reply;
    }
}
