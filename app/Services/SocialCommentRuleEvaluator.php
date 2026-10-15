<?php

namespace App\Services;

/**
 * Deterministic, side-effect-free evaluation shared by comment processing and
 * dry-run tools. This class never sends messages or touches provider APIs.
 */
class SocialCommentRuleEvaluator
{
    /** @return array<string, mixed> */
    public function evaluate(
        array $settings,
        string $commentText,
        ?string $postId = null,
        bool $automationEnabled = true,
        bool $publicReplyEnabled = true,
        bool $privateReplyEnabled = false,
        ?string $platform = null,
        ?string $eventType = 'comment_created',
        ?\DateTimeInterface $createdAt = null,
        bool $isLiveComment = false,
        bool $privateReplyAlreadySent = false,
    ): array {
        if (!$automationEnabled) {
            return $this->noMatch('rule_disabled');
        }

        $rules = $this->rules($settings);
        $sawEnabledRule = false;
        $sawInvalidRule = false;
        $sawPostMismatch = false;
        $sawExcludedKeyword = false;
        $blockedKeywords = [];

        foreach ($rules as $rule) {
            if (($rule['enabled'] ?? true) !== true) {
                continue;
            }
            $sawEnabledRule = true;

            $postIds = array_values(array_filter(array_map('strval', (array) ($rule['post_ids'] ?? []))));
            if ($postIds !== [] && ($postId === null || !in_array($postId, $postIds, true))) {
                $sawPostMismatch = true;
                continue;
            }

            $normalizedComment = self::normalize($commentText);
            $excluded = $this->matchingKeywords((array) ($rule['excluded_keywords'] ?? []), $normalizedComment, 'contains');
            if ($excluded !== []) {
                $sawExcludedKeyword = true;
                $blockedKeywords = array_values(array_unique([...$blockedKeywords, ...$excluded]));
                continue;
            }

            $mode = ($rule['match'] ?? 'contains') === 'equals' ? 'equals' : 'contains';
            $keywords = array_values(array_filter(array_map('strval', (array) ($rule['keywords'] ?? [])), fn ($word) => trim($word) !== ''));
            if ($keywords === []) {
                $sawInvalidRule = true;
                continue;
            }
            $matchedKeywords = $this->matchingKeywords($keywords, $normalizedComment, $mode);
            if ($matchedKeywords === []) {
                continue;
            }

            $requestedAction = in_array(($rule['action'] ?? 'public'), ['public', 'dm', 'both'], true)
                ? $rule['action'] ?? 'public'
                : 'public';
            $requested = $requestedAction === 'both' ? ['public', 'dm'] : [$requestedAction];
            $permitted = [];
            $actionReasons = [];

            foreach ($requested as $action) {
                if ($action === 'public') {
                    if ($publicReplyEnabled) $permitted[] = 'public';
                    else $actionReasons['public'] = 'public_reply_disabled';
                    continue;
                }

                if (!$privateReplyEnabled) {
                    $actionReasons['dm'] = 'private_reply_disabled';
                } elseif ($privateReplyAlreadySent) {
                    $actionReasons['dm'] = 'private_reply_already_sent';
                } elseif ($eventType !== 'comment_created' || !$createdAt) {
                    $actionReasons['dm'] = 'private_reply_ineligible';
                } elseif ($createdAt < now()->subDays(7)) {
                    $actionReasons['dm'] = 'private_reply_expired';
                } elseif ($platform === 'instagram' && $isLiveComment) {
                    $actionReasons['dm'] = 'private_reply_ineligible';
                } else {
                    $permitted[] = 'dm';
                }
            }

            $reason = $permitted === []
                ? (string) (reset($actionReasons) ?: 'no_permitted_action')
                : null;

            return [
                'matched' => true,
                'reason' => $reason,
                'rule' => [
                    'id' => (string) ($rule['id'] ?? 'legacy'),
                    'name' => (string) ($rule['name'] ?? 'Legacy comment trigger'),
                ],
                'matched_keywords' => $matchedKeywords,
                'excluded_keywords' => [],
                'requested_action' => $requestedAction,
                'action' => $permitted === ['public', 'dm'] ? 'both' : ($permitted[0] ?? 'none'),
                'permitted_actions' => $permitted,
                'action_reasons' => $actionReasons,
                'delay_minutes' => max(0, min(10080, (int) ($rule['delay_minutes'] ?? 0))),
                'public_reply_variants' => array_values(array_filter(array_map('strval', (array) ($rule['public_reply_variants'] ?? [])))),
                'dm_message' => isset($rule['dm_message']) ? (string) $rule['dm_message'] : null,
            ];
        }

        $result = $this->noMatch(!$sawEnabledRule ? 'rule_disabled' : ($sawExcludedKeyword ? 'excluded_keyword' : ($sawPostMismatch ? 'post_not_targeted' : ($sawInvalidRule ? 'invalid_configuration' : 'no_matching_rule'))));
        $result['excluded_keywords'] = $blockedKeywords;
        return $result;
    }

    public static function normalize(string $text): string
    {
        $text = mb_strtolower(trim($text), 'UTF-8');
        if (class_exists(\Normalizer::class)) {
            $text = \Normalizer::normalize($text, \Normalizer::FORM_C) ?: $text;
        }

        return preg_replace('/\s+/u', ' ', $text) ?? $text;
    }

    /** @return list<string> */
    private function matchingKeywords(array $keywords, string $normalizedComment, string $mode): array
    {
        $matches = [];
        foreach ($keywords as $keyword) {
            if (!is_string($keyword) || trim($keyword) === '') continue;
            $normalizedKeyword = self::normalize($keyword);
            if ($normalizedKeyword === '') continue;

            $matched = $mode === 'equals'
                ? $normalizedComment === $normalizedKeyword
                : preg_match('/(?<![\pL\pN])' . preg_quote($normalizedKeyword, '/') . '(?![\pL\pN])/u', $normalizedComment) === 1;
            if ($matched) $matches[] = $keyword;
        }

        return array_values(array_unique($matches));
    }

    /** @return list<array<string, mixed>> */
    private function rules(array $settings): array
    {
        if (isset($settings['rules']) && is_array($settings['rules'])) {
            return array_values(array_filter($settings['rules'], 'is_array'));
        }

        // Legacy settings remain effective until the configuration is migrated.
        return [[
            'id' => 'legacy',
            'name' => 'Legacy comment trigger',
            'keywords' => is_array($settings['trigger_keywords'] ?? null) ? $settings['trigger_keywords'] : [],
            'excluded_keywords' => is_array($settings['excluded_keywords'] ?? null) ? $settings['excluded_keywords'] : [],
            'match' => 'contains',
            'action' => 'public',
            'enabled' => true,
        ]];
    }

    /** @return array<string, mixed> */
    private function noMatch(string $reason): array
    {
        return [
            'matched' => false,
            'reason' => $reason,
            'rule' => null,
            'matched_keywords' => [],
            'excluded_keywords' => [],
            'requested_action' => 'none',
            'action' => 'none',
            'permitted_actions' => [],
            'action_reasons' => [],
            'delay_minutes' => 0,
            'public_reply_variants' => [],
            'dm_message' => null,
        ];
    }
}
