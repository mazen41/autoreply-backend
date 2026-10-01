<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

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
        $apiKey = env('OPENAI_API_KEY');

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
                    'language' => 'ar',
                    'response_format' => 'text',
                ]);

            if (!$response->successful()) {
                Log::error('Whisper transcription failed', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
                throw new \Exception('Transcription failed: ' . $response->body());
            }

            $transcription = $response->body();

            Log::info('Voice transcription completed', [
                'file' => basename($audioFilePath),
                'transcription_length' => strlen($transcription),
            ]);

            return trim($transcription);

        } catch (\Exception $e) {
            Log::error('Voice transcription error', [
                'error' => $e->getMessage(),
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
     * @return string Public URL of the generated audio file
     * @throws \Exception
     */
    public static function textToSpeech(string $text, string $voice = 'alloy'): string
    {
        $apiKey = env('OPENAI_API_KEY');

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
                    'body' => $response->body(),
                ]);
                throw new \Exception('TTS generation failed: ' . $response->body());
            }

            // Save audio file to storage
            $audioContent = $response->body();
            $fileName = 'tts/' . uniqid() . '.mp3';
            Storage::disk('public')->put($fileName, $audioContent);

            $publicUrl = Storage::disk('public')->url($fileName);

            Log::info('TTS audio generated', [
                'voice' => $voice,
                'text_length' => strlen($text),
                'file' => $fileName,
            ]);

            return $publicUrl;

        } catch (\Exception $e) {
            Log::error('TTS generation error', [
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Check if voice processing is available.
     */
    public static function isAvailable(): bool
    {
        return !empty(env('OPENAI_API_KEY'));
    }
}
