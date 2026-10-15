<?php

namespace Tests\Unit;

use App\Services\SocialCommentRuleEvaluator;
use PHPUnit\Framework\TestCase;

class SocialCommentRuleEvaluatorTest extends TestCase
{
    public function test_contains_matching_uses_word_boundaries_and_returns_a_traceable_rule(): void
    {
        $result = (new SocialCommentRuleEvaluator())->evaluate([
            'rules' => [[
                'id' => 'product-info', 'name' => 'Product info', 'keywords' => ['price'],
                'excluded_keywords' => [], 'match' => 'contains', 'action' => 'public', 'enabled' => true,
            ]],
        ], 'What is the PRICE?', postId: 'post-1');

        $this->assertTrue($result['matched']);
        $this->assertSame('product-info', $result['rule']['id']);
        $this->assertSame(['price'], $result['matched_keywords']);
        $this->assertSame('public', $result['action']);
    }

    public function test_contains_does_not_match_keyword_inside_an_unrelated_word(): void
    {
        $result = (new SocialCommentRuleEvaluator())->evaluate([
            'rules' => [[
                'id' => 'price', 'keywords' => ['hat'], 'match' => 'contains', 'action' => 'public', 'enabled' => true,
            ]],
        ], 'That is nice');

        $this->assertFalse($result['matched']);
        $this->assertSame('no_matching_rule', $result['reason']);
    }

    public function test_equals_requires_the_entire_normalized_comment(): void
    {
        $settings = ['rules' => [[
            'id' => 'exact', 'keywords' => ['price please'], 'match' => 'equals', 'action' => 'public', 'enabled' => true,
        ]]];
        $evaluator = new SocialCommentRuleEvaluator();

        $this->assertTrue($evaluator->evaluate($settings, ' PRICE PLEASE ' )['matched']);
        $this->assertFalse($evaluator->evaluate($settings, 'price please thanks')['matched']);
    }

    public function test_excluded_keyword_blocks_matching_rule_and_post_target_is_enforced(): void
    {
        $settings = ['rules' => [[
            'id' => 'targeted', 'keywords' => ['details'], 'excluded_keywords' => ['spam'],
            'match' => 'contains', 'action' => 'public', 'post_ids' => ['wanted'], 'enabled' => true,
        ]]];
        $evaluator = new SocialCommentRuleEvaluator();

        $excluded = $evaluator->evaluate($settings, 'send details, spam please', postId: 'wanted');
        $wrongPost = $evaluator->evaluate($settings, 'send details', postId: 'other');

        $this->assertFalse($excluded['matched']);
        $this->assertSame('excluded_keyword', $excluded['reason']);
        $this->assertFalse($wrongPost['matched']);
        $this->assertSame('post_not_targeted', $wrongPost['reason']);
    }

    public function test_legacy_keyword_settings_are_evaluated_without_broadening_the_match(): void
    {
        $result = (new SocialCommentRuleEvaluator())->evaluate([
            'trigger_keywords' => ['price'], 'excluded_keywords' => ['spam'],
        ], 'Could you share the price?');

        $this->assertTrue($result['matched']);
        $this->assertSame('legacy', $result['rule']['id']);
        $this->assertSame(['price'], $result['matched_keywords']);
    }

    public function test_empty_keyword_rules_never_match_every_comment(): void
    {
        $evaluator = new SocialCommentRuleEvaluator();

        $structured = $evaluator->evaluate(['rules' => [[
            'id' => 'empty', 'keywords' => [], 'match' => 'contains', 'action' => 'public', 'enabled' => true,
        ]]], 'A comment with no trigger');
        $legacy = $evaluator->evaluate(['trigger_keywords' => []], 'A comment with no trigger');

        $this->assertFalse($structured['matched']);
        $this->assertSame('invalid_configuration', $structured['reason']);
        $this->assertFalse($legacy['matched']);
        $this->assertSame('invalid_configuration', $legacy['reason']);
    }

    public function test_arabic_keywords_match_without_substring_false_positives(): void
    {
        $evaluator = new SocialCommentRuleEvaluator();
        $settings = ['rules' => [[
            'id' => 'arabic-price', 'keywords' => ['سعر'], 'match' => 'contains', 'action' => 'public', 'enabled' => true,
        ]]];

        $this->assertTrue($evaluator->evaluate($settings, 'ما سعر المنتج؟')['matched']);
        $this->assertFalse($evaluator->evaluate($settings, 'السعر رائع')['matched']);
    }

    public function test_first_matching_rule_wins_deterministically_and_disabled_rules_are_ignored(): void
    {
        $result = (new SocialCommentRuleEvaluator())->evaluate(['rules' => [
            ['id' => 'disabled', 'keywords' => ['price'], 'action' => 'public', 'enabled' => false],
            ['id' => 'first-enabled', 'keywords' => ['price'], 'action' => 'public', 'enabled' => true],
            ['id' => 'second-enabled', 'keywords' => ['price'], 'action' => 'public', 'enabled' => true],
        ]], 'price');

        $this->assertTrue($result['matched']);
        $this->assertSame('first-enabled', $result['rule']['id']);
    }
}
