<?php

namespace App\Services;

use App\Models\Message;

/**
 * A customer who greets again must not receive the same welcome text. This is
 * decided here, not left to the model: if the reply repeats the welcome message
 * or the previous AI reply, a short varied reply in the customer's language is used.
 */
class GreetingRepeatGuard
{
    private const GREETING = '/^\s*(hi+|hello|hey|hiya|good\s+(morning|evening|afternoon)|مرحبا|مرحباً|اهلا|أهلا|اهلاً|أهلاً|هلا|هاي|ازيك|ازيكم|السلام\s+عليكم|صباح\s+الخير|مساء\s+الخير)[\s!.,?؟]*$/iu';

    private const EN = [
        'Hi again! What can I help you with today?',
        'Hello! Happy to help. What are you looking for?',
        'Hey there! Tell me what you need: products, prices, or an order.',
    ];

    private const AR = [
        'أهلاً مرة أخرى! أقدر أساعدك في إيه النهارده؟',
        'أهلاً بيك! قولي عايز تسأل عن إيه؟',
        'موجود لمساعدتك. تحب تشوف منتجات ولا تسأل عن سعر؟',
    ];

    /** @return array{reply: string, replaced: bool, previous_greetings: int} */
    public function apply(Message $inbound, string $reply): array
    {
        $text = trim((string) $inbound->content);
        if ($text === '' || preg_match(self::GREETING, $text) !== 1) {
            return ['reply' => $reply, 'replaced' => false, 'previous_greetings' => 0];
        }

        $previous = Message::query()
            ->where('conversation_id', $inbound->conversation_id)
            ->where('direction', 'inbound')
            ->where('id', '<', $inbound->id)
            ->whereNotNull('content')
            ->pluck('content')
            ->filter(fn ($content) => preg_match(self::GREETING, trim((string) $content)) === 1)
            ->count();

        $repeatsCheckoutPrompt = preg_match(
            '/(?:order\s+(?:has\s+)?not\s+(?:been\s+)?submitted|have\s+not\s+submitted\s+the\s+order|haven.t\s+submitted\s+the\s+order|please\s+send\b|to\s+complete\s+(?:the\s+)?order|couldn.t\s+identify\s+the\s+delivery\s+city|لم\s+يتم\s+إرسال\s+الطلب)/iu',
            $reply
        ) === 1;

        if ($previous === 0 && !$repeatsCheckoutPrompt) {
            return ['reply' => $reply, 'replaced' => false, 'previous_greetings' => 0];
        }

        // A bare greeting during checkout is still a greeting. Some providers
        // over-prioritize saved checkout instructions and answer "hi" by
        // repeating the missing-fields prompt. Replace that detour even on the
        // first greeting; keep the checkout state intact for the next turn.
        // Once a customer has greeted before, treat a new bare greeting as a
        // greeting even when the model produces a different checkout-related
        // reply. That reply can otherwise slip past repeat-text detection and
        // then be rewritten back into the same missing-fields prompt later.
        $pool = preg_match('/\p{Arabic}/u', $text) === 1 ? self::AR : self::EN;

        return [
            'reply' => $pool[max(0, $previous - 1) % count($pool)],
            'replaced' => true,
            'previous_greetings' => $previous,
        ];
    }
}
