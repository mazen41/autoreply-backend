<?php

namespace Tests\Feature;

use App\Models\BusinessProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MultimodalImageAnalysisTest extends TestCase
{
    use RefreshDatabase;

    public function test_image_test_endpoint_returns_provider_analysis_and_cleans_temporary_upload(): void
    {
        Storage::fake('private_media');
        config([
            'services.evolution.incoming_media_disk' => 'private_media',
            'services.vision.provider' => 'openai',
            'services.vision.openai_api_key' => 'test-provider-key',
        ]);
        $user = User::factory()->create();
        $business = BusinessProfile::factory()->create([
            'user_id' => $user->id,
            'media_processing_disclosed_at' => now(),
        ]);
        Sanctum::actingAs($user);

        Http::fake([
            'api.openai.com/v1/chat/completions' => Http::response([
                'choices' => [[
                    'message' => ['content' => json_encode([
                        'description' => 'A blue ceramic mug',
                        'extracted_text' => '',
                        'product_matches' => [],
                        'confidence' => 0.91,
                        'detected_intent' => 'product_inquiry',
                    ])],
                ]],
            ]),
        ]);

        $response = $this->withHeader('X-Business-Id', (string) $business->id)
                ->post('/api/multimodal/test-image', [
                    'image' => UploadedFile::fake()->image('sample.png'),
                ])->assertOk()
                ->assertJsonPath('description', 'A blue ceramic mug')
                ->assertJsonPath('detected_intent', 'product_inquiry')
                ->assertJsonPath('confidence', 0.91);

        $this->assertNotEmpty($response->json());
        $this->assertSame([], Storage::disk('private_media')->allFiles());
        Http::assertSent(fn ($request) => $request->url() === 'https://api.openai.com/v1/chat/completions');
    }

    public function test_image_test_endpoint_requires_media_processing_disclosure(): void
    {
        $user = User::factory()->create();
        $business = BusinessProfile::factory()->create([
            'user_id' => $user->id,
            'media_processing_disclosed_at' => null,
        ]);
        Sanctum::actingAs($user);

        $this->withHeader('X-Business-Id', (string) $business->id)
            ->post('/api/multimodal/test-image', [
                'image' => UploadedFile::fake()->image('sample.png'),
            ])
            ->assertForbidden();
    }
}
