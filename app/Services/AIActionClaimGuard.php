<?php

namespace App\Services;

/** Prevent unsupported claims that the bot changed a saved customer/store record. */
class AIActionClaimGuard
{
    public function inspect(string $reply, string $language = 'english', bool $verifiedMutationSucceeded = false): array
    {
        $claimsAddressMutation = preg_match(
            '/\b(?:i|we)\s+(?:have\s+)?(?:updated|changed|saved|corrected)\s+(?:your|the)\s+(?:delivery\s+)?address\b|\b(?:your|the)\s+(?:delivery\s+)?address\s+(?:has\s+been|was)\s+(?:updated|changed|saved)\b|\b(?:i|we)\s+(?:have\s+)?(?:updated|saved|changed)\s+(?:your|the)\s+(?:customer\s+)?(?:account|profile)\s+details\b|(?:تم\s+(?:تحديث|حفظ|تغيير)\s+(?:عنوانك|عنوان التوصيل|بيانات حسابك)|(?:عنوانك|عنوان التوصيل)\s+(?:اتحدث|تم تحديثه|اتحفظ))/ui',
            $reply
        ) === 1;

        if (!$claimsAddressMutation || $verifiedMutationSucceeded) {
            return ['blocked' => false, 'replacement' => null, 'reason' => null];
        }

        $replacement = $language === 'arabic'
            ? 'ما غيّرتش العنوان المحفوظ في المتجر. العنوان اللي بعتّه موجود في المحادثة بس؛ تقدر تحدّث عنوانك من حسابك في المتجر، أو أقدر أطلب من فريق الدعم يساعدك.'
            : "I haven’t changed the saved address in the store. The address you sent is only in this conversation; you can update your saved address in your store account, or I can ask the support team to help.";

        return ['blocked' => true, 'replacement' => $replacement, 'reason' => 'unverified_customer_record_mutation'];
    }
}
