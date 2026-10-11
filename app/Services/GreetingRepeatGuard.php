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

    private const WELCOME = '/welcome to naz autoreply|اهلا بيك في|مرحبا بك في|أهلاً بك في/iu';

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

        if ($previous === 0) {
            return ['reply' => $reply, 'replaced' => false, 'previous_greetings' => 0];
        }

        $lastAi = Message::query()
            ->where('conversation_id', $inbound->conversation_id)
            ->where('direction', 'outbound')
            ->where('is_ai', true)
            ->where('id', '<', $inbound->id)
            ->latest('id')
            ->value('content');

        $repeatsWelcome = preg_match(self::WELCOME, $reply) === 1;
        $repeatsLast = $lastAi !== null && $this->normalize((string) $lastAi) === $this->normalize($reply);

        if (!$repeatsWelcome && !$repeatsLast) {
            return ['reply' => $reply, 'replaced' => false, 'previous_greetings' => $previous];
        }

        $pool = preg_match('/\p{Arabic}/u', $text) === 1 ? self::AR : self::EN;

        return [
            'reply' => $pool[($previous - 1) % count($pool)],
            'replaced' => true,
            'previous_greetings' => $previous,
        ];
    }

    private function normalize(string $value): string
    {
        return mb_strtolower(preg_replace('/\s+/u', ' ', trim($value)) ?? '');
    }
}