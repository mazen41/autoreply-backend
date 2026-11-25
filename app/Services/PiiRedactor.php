<?php

namespace App\Services;

class PiiRedactor
{
    /** @return array{text: string, types: array<int, string>} */
    public function redact(string $text): array
    {
        $types = [];
        $replace = function (string $pattern, string $label, ?callable $shouldReplace = null) use (&$text, &$types): void {
            $text = preg_replace_callback($pattern, function (array $match) use ($label, $shouldReplace, &$types): string {
                if ($shouldReplace && !$shouldReplace($match[0])) {
                    return $match[0];
                }
                $types[$label] = $label;
                return '[REDACTED_' . strtoupper($label) . ']';
            }, $text) ?? $text;
        };

        $replace('/(?<![\p{L}\p{N}])(?:full\s+name|customer\s+name|الاسم الكامل|اسم العميل)\s*:\s*[^\r\n,;]+/iu', 'name');
        $replace('/(?<![\p{L}\p{N}])(?:delivery\s+address|street\s+address|address|عنوان التوصيل|العنوان)\s*:\s*[^\r\n]+/iu', 'address');
        $replace('/(?<![\p{L}\p{N}])(?:order|طلب)\s*(?:(?:number|no\.?|رقم)\s*)?#?\s*[\p{N}]{7,}|(?<![\p{L}\p{N}])#[\p{N}]{7,}/iu', 'order_number');
        $replace('/\b(?:https?:\/\/|www\.)[^\s<]+/iu', 'url');
        $replace('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/iu', 'email');
        $replace(
            '/(?<![\p{L}\p{N}])(?:\+|00)?[\p{N}][\p{N} ().-]{5,}[\p{N}](?![\p{L}\p{N}])/u',
            'phone',
            static function (string $value): bool {
                preg_match_all('/\p{N}/u', $value, $digits);
                $count = count($digits[0] ?? []);
                return $count >= 7 && $count <= 15;
            }
        );

        return ['text' => $text, 'types' => array_values($types)];
    }
}
