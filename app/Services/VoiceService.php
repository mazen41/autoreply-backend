<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;

class VoiceService
{
    /**
     * Transcribe an audio file using OpenAI Whisper API.
     *
     * @param string $audioFilePath Absolute path to the audio file
     * @return string Transcribed text
     * @throws \Exception
     */
    public static function transcribeAudio(string $audioFilePath): string
    {
        $apiKey = config('services.openai.api_key');

        if (!$apiKey) {
            throw new \Exception('OpenAI API key not configured for voice transcription');
        }

        if (!file_exists($audioFilePath)) {
            throw new \Exception("Audio file not found: {$audioFilePath}");
        }

        try {
            $response = Http::timeout(60)
                ->withHeaders([
                    'Authorization' => 'Bearer ' . $apiKey,
                ])
                ->attach('file', file_get_contents($audioFilePath), basename($audioFilePath))
                ->post('https://api.openai.com/v1/audio/transcriptions', [
                    'model' => 'whisper-1',
                    'response_format' => 'text',
                ]);

            if (!$response->successful()) {
                Log::error('Whisper transcription failed', [
                    'status' => $response->status(),
                ]);
                throw new \Exception('Transcription failed with HTTP status ' . $response->status());
            }

            $transcription = $response->body();

            Log::info('Voice transcription completed', [
                'file' => basename($audioFilePath),
                'transcription_length' => strlen($transcription),
            ]);

            return trim($transcription);

        } catch (\Exception $e) {
            Log::error('Voice transcription error', [
                'exception_type' => get_class($e),
                'file' => basename($audioFilePath),
            ]);
            throw $e;
        }
    }

    /**
     * Convert text to speech using OpenAI TTS API.
     *
     * @param string $text The text to convert to speech
     * @param string $voice The voice to use (alloy, echo, fable, onyx, nova, shimmer)
     * @return string Expiring signed URL for private playback of the generated audio
     * @throws \Exception
     */
    public static function textToSpeech(string $text, string $voice = 'alloy'): string
    {
        $apiKey = config('services.openai.api_key');

        if (!$apiKey) {
            throw new \Exception('OpenAI API key not configured for text-to-speech');
        }

        $validVoices = ['alloy', 'echo', 'fable', 'onyx', 'nova', 'shimmer'];
        if (!in_array($voice, $validVoices)) {
            $voice = 'alloy';
        }

        try {
            $response = Http::timeout(60)
                ->withHeaders([
                    'Authorization' => 'Bearer ' . $apiKey,
                    'Content-Type' => 'application/json',
                ])
                ->post('https://api.openai.com/v1/audio/speech', [
                    'model' => 'tts-1',
                    'input' => $text,
                    'voice' => $voice,
                    'response_format' => 'mp3',
                ]);

            if (!$response->successful()) {
                Log::error('TTS generation failed', [
                    'status' => $response->status(),
                ]);
                throw new \Exception('TTS generation failed with HTTP status ' . $response->status());
            }

            // Keep generated speech private and expose only an expiring signed URL.
            $audioContent = $response->body();
            $storage = app(IncomingMediaStorage::class);
            $fileName = $storage->store($audioContent, 'mp3');
            $signedUrl = URL::temporarySignedRoute('media.tts.show', now()->addDays(7), [
                'mediaPath' => $fileName,
            ]);

            Log::info('TTS audio generated', [
                'voice' => $voice,
                'text_length' => strlen($text),
                'file' => $fileName,
            ]);

            return $signedUrl;

        } catch (\Exception $e) {
            Log::error('TTS generation error', [
                'exception_type' => get_class($e),
            ]);
            throw $e;
        }
    }

    /**
     * Check if voice processing is available.
     */
    public static function isAvailable(): bool
    {
        return !empty(config('services.openai.api_key'));
    }
}
