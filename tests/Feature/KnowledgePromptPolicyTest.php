<?php

namespace Tests\Feature;

use App\Models\BusinessFeatureFlagAssignment;
use App\Models\BusinessProfile;
use App\Models\FeatureFlag;
use App\Models\User;
use App\Services\AICapabilitiesService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\TestCase;

class KnowledgePromptPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_grounded_knowledge_prompt_is_opt_in_and_covers_conflicts_and_source_names(): void
    {
        [$business, $flag] = $this->businessWithGroundingFlag();
        $context = [
            'business_id' => $business->id,
            'business_profile' => 'Return policy: 30 days.',
            'knowledge_base' => '[Source: returns.pdf] Return policy: 30 days.',
            'knowledge_status' => 'ok',
        ];

        $legacyPrompt = $this->prompt($context);
        $this->assertStringContainsString('If partially available → combine with reasoning.', $legacyPrompt);
        $this->assertStringNotContainsString('GROUNDED KNOWLEDGE RULES', $legacyPrompt);

        BusinessFeatureFlagAssignment::create([
            'business_id' => $business->id,
            'feature_flag_id' => $flag->id,
            'enabled' => true,
        ]);
        $groundedPrompt = $this->prompt($context);

        $this->assertStringContainsString('Answer only from the supplied business profile and uploaded knowledge sources.', $groundedPrompt);
        $this->assertStringContainsString('If two supplied sources conflict', $groundedPrompt);
        $this->assertStringContainsString('only if the customer asks where the information came from', $groundedPrompt);
        $this->assertStringNotContainsString('If partially available → combine with reasoning.', $groundedPrompt);
    }

    public function test_salla_exclusive_prompt_does_not_receive_grounded_knowledge_rules(): void
    {
        [$business, $flag] = $this->businessWithGroundingFlag();
        BusinessFeatureFlagAssignment::create([
            'business_id' => $business->id,
            'feature_flag_id' => $flag->id,
            'enabled' => true,
        ]);

        $prompt = $this->prompt([
            'business_id' => $business->id,
            'salla_exclusive_mode' => true,
            'business_profile' => '',
            'knowledge_base' => '',
        ]);

        $this->assertStringNotContainsString('GROUNDED KNOWLEDGE RULES', $prompt);
    }

    private function businessWithGroundingFlag(): array
    {
        $business = BusinessProfile::factory()->create(['user_id' => User::factory()->create()->id]);
        $flag = FeatureFlag::query()->where('key', 'knowledge_relevance_floor')->firstOrFail();
        return [$business, $flag];
    }

    private function prompt(array $context): string
    {
        $method = new ReflectionMethod(AICapabilitiesService::class, 'getUltimateSystemPrompt');
        $method->setAccessible(true);
        return $method->invoke(null, $context);
    }
}
