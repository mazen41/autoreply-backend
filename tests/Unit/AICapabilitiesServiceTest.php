<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Services\AICapabilitiesService;

class AICapabilitiesServiceTest extends TestCase
{
    public function test_detect_intent_greeting_arabic()
    {
        // Use simple fallback detection now
        $result = AICapabilitiesService::detectIntent('السلام عليكم');
        
        $this->assertArrayHasKey('intent', $result);
        $this->assertArrayHasKey('confidence', $result);
        $this->assertEquals('greeting', $result['intent']);
        $this->assertGreaterThan(0.7, $result['confidence']);
    }

    public function test_detect_intent_greeting_english()
    {
        // Use simple fallback detection now
        $result = AICapabilitiesService::detectIntent('hello there');
        
        $this->assertArrayHasKey('intent', $result);
        $this->assertArrayHasKey('confidence', $result);
        $this->assertEquals('greeting', $result['intent']);
        $this->assertGreaterThan(0.7, $result['confidence']);
    }

    public function test_detect_intent_order_query()
    {
        // Use simple fallback detection now
        $result = AICapabilitiesService::detectIntent('فين طلبي؟');
        
        $this->assertArrayHasKey('intent', $result);
        $this->assertArrayHasKey('confidence', $result);
        $this->assertEquals('order', $result['intent']);
    }

    public function test_handle_message_complete_pipeline()
    {
        // This test is skipped in CI/CD to avoid API calls
        $this->markTestSkipped('Skipping handleMessage test to avoid API calls in CI');
        
        $result = AICapabilitiesService::handleMessage('السلام عليكم', [
            'business_name' => 'Test Business',
            'language' => 'arabic'
        ]);
        
        $this->assertArrayHasKey('intent', $result);
        $this->assertArrayHasKey('response', $result);
        $this->assertArrayHasKey('confidence', $result);
        $this->assertArrayHasKey('issues', $result);
        $this->assertEquals('greeting', $result['intent']);
        $this->assertGreaterThan(70, $result['confidence']);
    }

    public function test_calculate_confidence_formula()
    {
        $intent = ['intent' => 'greeting', 'confidence' => 0.9];
        $evaluation = ['confidence' => 0.8, 'issues' => []];
        $contextScore = 0.5;
        
        $confidence = AICapabilitiesService::calculateConfidence($intent, $evaluation, $contextScore);
        
        // Formula: (0.9 * 0.5) + (0.8 * 0.3) + (0.5 * 0.2) = 0.45 + 0.24 + 0.10 = 0.79 = 79%
        $this->assertEquals(79, $confidence);
    }

    public function test_evaluate_response_quality()
    {
        // Test the simple heuristic fallback
        $evaluation = AICapabilitiesService::evaluateResponse(
            'What is your price?',
            'Our prices start at $10 for basic plans.'
        );
        
        $this->assertArrayHasKey('confidence', $evaluation);
        $this->assertArrayHasKey('issues', $evaluation);
        $this->assertGreaterThan(0.5, $evaluation['confidence']);
    }

    public function test_short_message_skip_ai()
    {
        $result = AICapabilitiesService::detectIntent('h');
        
        // Very short messages (< 2 chars) should skip AI and return unknown
        $this->assertEquals('unknown', $result['intent']);
        $this->assertEquals(0.5, $result['confidence']);
    }

    public function test_context_score_calculation()
    {
        $context = [
            'has_store_data' => true,
            'has_product_info' => true,
            'has_order_data' => false
        ];
        
        $score = AICapabilitiesService::calculateContextScore($context);
        
        // 0.3 + 0.3 + 0 = 0.6
        $this->assertEquals(0.6, $score);
    }

    public function test_detect_handoff_with_ai()
    {
        // This test is skipped in CI/CD to avoid API calls
        $this->markTestSkipped('Skipping detectHandoff test to avoid API calls in CI');
        
        $history = [
            ['direction' => 'inbound', 'content' => 'This is terrible service'],
            ['direction' => 'outbound', 'content' => 'I apologize for the issue'],
            ['direction' => 'inbound', 'content' => 'I want to speak to a human']
        ];
        
        $result = AICapabilitiesService::detectHandoff('I want to speak to a human', $history);
        
        $this->assertArrayHasKey('should_escalate', $result);
        $this->assertArrayHasKey('confidence', $result);
        $this->assertArrayHasKey('reasons', $result);
    }
}
