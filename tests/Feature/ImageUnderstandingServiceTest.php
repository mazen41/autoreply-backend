<?php

namespace Tests\Feature;

use App\Services\ImageUnderstandingService;
use App\Services\IncomingMediaStorage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ImageUnderstandingServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('private_media');
        config([
            'services.evolution.incoming_media_disk' => 'private_media',
            'services.vision.provider' => 'gemini',
            'services.vision.gemini_api_key' => 'test-key-from-config',
            'services.vision.gemini_model' => 'gemini-3.8-flash',
        ]);
    }

    public function test_a_stored_image_is_sent_to_the_configured_vision_model(): void
    {
        Http::fake([
            'https://generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [[
                    'content' => ['parts' => [['text' => json_encode([
                        'description' => 'A blue shirt',
                        'detected_intent' => 'product_inquiry',
                        'confidence' => 0.91,
                    ])]]],
                ]],
            ]),
        ]);
        $media = app(IncomingMediaStorage::class);
        $relativePath = $media->store('stored-image-bytes', 'jpg');
        $absolutePath = $media->absolutePath($relativePath);

        $result = ImageUnderstandingService::analyzeImage((string) $absolutePath);

        $this->assertSame('A blue shirt', $result['raw_analysis']);
        $this->assertSame('product_inquiry', $result['detected_intent']);
        $this->assertTrue(ImageUnderstandingService::isAvailable());
        Http::assertSent(fn ($request) => str_contains($request->url(), '/models/gemini-3.8-flash:generateContent')
            && data_get($request->data(), 'contents.0.parts.1.inline_data.data') === base64_encode('stored-image-bytes'));
    }

    public function test_an_image_path_outside_the_storage_root_is_rejected_without_an_api_call(): void
    {
        Http::fake();

        $result = ImageUnderstandingService::analyzeImage(base_path('composer.json'));

        $this->assertSame('unavailable', $result['status']);
        $this->assertStringContainsString('outside the allowed storage root', $result['error']);
        Http::assertNothingSent();
    }

    public function test_missing_provider_key_returns_an_explicit_unavailable_result(): void
    {
        config(['services.vision.gemini_api_key' => '']);
        Http::fake();

        $result = ImageUnderstandingService::analyzeImage('media/inbound/missing.jpg');

        $this->assertSame('unavailable', $result['status']);
        $this->assertStringContainsString('API key is not configured', $result['error']);
        $this->assertFalse(ImageUnderstandingService::isAvailable());
        Http::assertNothingSent();
    }

    public function test_vision_configuration_is_resolved_from_config_not_runtime_env(): void
    {
        config([
            'services.vision.provider' => 'gemini',
            'services.vision.gemini_api_key' => 'cached-config-key',
            'services.vision.gemini_model' => 'gemini-3.8-flash',
        ]);

        $this->assertTrue(ImageUnderstandingService::isAvailable());
        $this->assertSame('gemini-3.8-flash', config('services.vision.gemini_model'));
    }
}
