<?php

namespace Tests\Feature;

use App\Models\BusinessProfile;
use App\Models\FeatureFlag;
use App\Jobs\ProcessAutoReply;
use App\Services\AICapabilitiesService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SallaWorkflowGuardrailFlagTest extends TestCase
{
    use RefreshDatabase;

    public function test_arabic_handoff_phrases_are_business_opt_in_and_do_not_escalate_by_default(): void
    {
        $business = BusinessProfile::factory()->create();
        $phrase = 'محتاج أكلم خدمة العملاء';

        $this->assertFalse(AICapabilitiesService::checkHardEscalation($phrase, $business->id)['force_escalation']);

        $flag = FeatureFlag::query()->where('key', 'salla_workflow_guardrails')->firstOrFail();
        DB::table('business_feature_flag_assignments')->insert([
            'business_id' => $business->id,
            'feature_flag_id' => $flag->id,
            'enabled' => true,
            'enabled_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertTrue(AICapabilitiesService::checkHardEscalation($phrase, $business->id)['force_escalation']);
        $this->assertFalse(AICapabilitiesService::checkHardEscalation('What customer service do you offer?', $business->id)['force_escalation']);
    }

    public function test_shipping_fee_guardrail_prompt_is_only_included_for_opted_in_businesses(): void
    {
        $business = BusinessProfile::factory()->create();
        $method = new \ReflectionMethod(AICapabilitiesService::class, 'getUltimateSystemPrompt');
        $method->setAccessible(true);
        $context = ['business_id' => $business->id, 'language' => 'english', 'platform' => 'messenger'];

        $this->assertStringNotContainsString('workflow guardrails', $method->invoke(null, $context));

        $flag = FeatureFlag::query()->where('key', 'salla_workflow_guardrails')->firstOrFail();
        DB::table('business_feature_flag_assignments')->insert([
            'business_id' => $business->id,
            'feature_flag_id' => $flag->id,
            'enabled' => true,
            'enabled_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertStringContainsString('workflow guardrails', $method->invoke(null, $context));
    }

    public function test_additional_arabic_checkout_cancellation_phrases_are_opt_in(): void
    {
        $business = BusinessProfile::factory()->create();
        $job = new ProcessAutoReply(1);
        $method = new \ReflectionMethod(ProcessAutoReply::class, 'isExplicitCheckoutCancellation');
        $method->setAccessible(true);
        $phrase = 'خلاص الغي الطلب';

        $this->assertFalse($method->invoke($job, $phrase, $business->id));
        $this->enableGuardrails($business->id);
        $this->assertTrue($method->invoke($job, $phrase, $business->id));
    }

    private function enableGuardrails(int $businessId): void
    {
        $flag = FeatureFlag::query()->where('key', 'salla_workflow_guardrails')->firstOrFail();
        DB::table('business_feature_flag_assignments')->insert([
            'business_id' => $businessId,
            'feature_flag_id' => $flag->id,
            'enabled' => true,
            'enabled_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
