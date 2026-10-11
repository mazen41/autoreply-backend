<?php

namespace Tests\Feature;

use App\Services\VoiceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class VoiceServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_transcription_does_not_force_arabic_language(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('voice/sample.ogg', 'audio bytes');
        config(['services.openai.api_key' => 'test-openai-key']);
        Http::fake(['api.openai.com/v1/audio/transcriptions' => Http::response('Hello there', 200)]);

        $transcription = VoiceService::transcribeAudio(Storage::disk('local')->path('voice/sample.ogg'));

        $this->assertSame('Hello there', $transcription);
        Http::assertSent(fn ($request) => $request->url() === 'https://api.openai.com/v1/audio/transcriptions'
            && !array_key_exists('language', $request->data()));
    }

    public function test_generated_speech_is_private_and_available_through_an_expiring_signed_url(): void
    {
        Storage::fake('private_media');
        config(['services.evolution.incoming_media_disk' => 'private_media']);
        config(['services.openai.api_key' => 'test-openai-key']);
        Http::fake(['api.openai.com/v1/audio/speech' => Http::response('generated-audio-bytes', 200)]);

        $url = VoiceService::textToSpeech('A private reply');
        $this->assertTrue(URL::hasValidSignature(\Illuminate\Http\Request::create($url)));
        $path = parse_url($url, PHP_URL_PATH);
        $this->assertStringContainsString('/api/media/tts/media/inbound/', $path);
        $relativePath = substr($path, strlen('/api/media/tts/'));
        Storage::disk('private_media')->assertExists($relativePath);
        Storage::disk('public')->assertMissing($relativePath);

        $this->get($url)->assertOk()->assertHeader('Content-Type', 'audio/mpeg');
        $this->get($path . '?expires=1&signature=invalid')->assertForbidden();
    }
}
