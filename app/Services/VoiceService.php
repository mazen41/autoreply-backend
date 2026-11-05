<?php

namespace App\Services;

use App\Exceptions\AiProviderRequestException;
use App\Models\BusinessProfile;
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
    public static function transcribeAudio(string $audioFilePath, ?BusinessProfile $business = null, ?string $operationId = null, ?int $durationSeconds = null): string
    {
        $provider = (string) config('services.voice.transcription_provider', 'openai');
        if ($provider !== 'openai') {
            throw new \Exception('Unsupported voice transcription provider: ' . $provider);
        }

        $apiKey = app(AiProviderConfiguration::class)->key('openai');

        if (!$apiKey) {
            throw new \Exception('OpenAI API key not configured for voice transcription');
        }

        if (!file_exists($audioFilePath)) {
            throw new \Exception("Audio file not found: {$audioFilePath}");
        }

        if (!$business) throw new \RuntimeException('Audio transcription requires an authorized business credit wallet.');
        $route = app(AiRouter::class)->resolve($business, 'audio_transcription', 'openai', (string) config('services.voice.transcription_model', 'whisper-1'), ['audio_transcription']);
        if ($route['provider'] !== 'openai') throw new \RuntimeException('The selected audio transcription provider is not supported by this adapter.');
        $model = (string) $route['model'];
        $durationSeconds = max(1, $durationSeconds ?? self::estimateDurationSeconds($audioFilePath));
        $result = app(AiGateway::class)->execute(
            $business, 'audio_transcription', 'openai', $model, 0, 0,
            function () use ($audioFilePath, $apiKey, $model, $durationSeconds, $route): array {
                $candidates = [['provider' => 'openai', 'model_key' => $model]];
                foreach (($route['fallback_models'] ?? []) as $fallback) {
                    if (($fallback['provider'] ?? null) === 'openai') $candidates[] = ['provider' => 'openai', 'model_key' => $fallback['model_key']];
                }
                $last = null;
                foreach (array_slice($candidates, 0, 1 + max(0, (int) ($route['max_retries'] ?? 1))) as $candidate) {
                    $candidateModel = (string) $candidate['model_key'];
                    try {
                        $response = Http::timeout(app(AiProviderConfiguration::class)->timeoutSeconds('openai'))->withToken($apiKey)
                            ->attach('file', file_get_contents($audioFilePath), basename($audioFilePath))
                            ->post('https://api.openai.com/v1/audio/transcriptions', ['model' => $candidateModel, 'response_format' => 'verbose_json']);
                        if (!$response->successful()) throw new AiProviderRequestException('OpenAI audio transcription', $response->status());
                        $actualDuration = (int) round((float) $response->json('duration', $durationSeconds));
                        $text = (string) $response->json('text', '');
                        return ['content' => $text, 'transcription' => trim($text), 'provider' => 'openai', 'model' => $candidateModel, 'usage' => ['input_tokens' => 0, 'output_tokens' => 0, 'audio_seconds' => max(1, $actualDuration)]];
                    } catch (AiProviderRequestException $exception) {
                        $last = $exception;
                        if (!$exception->isRetryableUnbilled()) break;
                    }
                }
                throw $last ?? new \RuntimeException('No eligible audio transcription model is available.');
            },
            ['modality' => 'audio', 'estimated_audio_seconds' => $durationSeconds, 'routing_source' => $route['source'], 'routing_rule_id' => $route['rule_id'], 'fallback_model_ids' => $route['fallback_model_ids'] ?? []],
            $operationId,
        );
        return trim((string) ($result['transcription'] ?? ''));
    }

    private static function estimateDurationSeconds(string $path): int
    {
        if (class_exists('getID3')) {
            try {
                $analyzer = new \getID3();
                $info = $analyzer->analyze($path);
                if (is_numeric($info['playtime_seconds'] ?? null)) return max(1, (int) ceil((float) $info['playtime_seconds']));
            } catch (\Throwable) {
                // Continue with the conservative fallback reservation.
            }
        }
        return 60;
    }

    /**
     * Convert text to speech using OpenAI TTS API.
     *
     * @param string $text The text to convert to speech
     * @param string $voice The voice to use (alloy, echo, fable, onyx, nova, shimmer)
     * @return string Expiring signed URL for private playback of the generated audio
     * @throws \Exception
     */
    public static function textToSpeech(string $text, string $voice = 'alloy', ?BusinessProfile $business = null, ?string $operationId = null): string
    {
        $apiKey = app(AiProviderConfiguration::class)->key('openai');

        if (!$apiKey) {
            throw new \Exception('OpenAI API key not configured for text-to-speech');
        }

        $validVoices = ['alloy', 'echo', 'fable', 'onyx', 'nova', 'shimmer'];
        if (!in_array($voice, $validVoices)) {
            $voice = 'alloy';
        }

        if (!$business) throw new \RuntimeException('Text-to-speech requires an authorized business credit wallet.');

        $route = app(AiRouter::class)->resolve($business, 'audio_generation', 'openai', (string) config('services.voice.speech_model', 'tts-1'), ['audio_generation']);
        if ($route['provider'] !== 'openai') throw new \RuntimeException('The selected text-to-speech provider is not supported by this adapter.');

        try {
            $result = app(AiGateway::class)->execute(
                $business,
                'audio_generation',
                (string) $route['provider'],
                (string) $route['model'],
                0,
                0,
                function () use ($apiKey, $text, $voice, $route): array {
                    $models = [['provider' => 'openai', 'model_key' => (string) $route['model']]];
                    foreach (($route['fallback_models'] ?? []) as $fallback) {
                        if (($fallback['provider'] ?? null) === 'openai') $models[] = ['provider' => 'openai', 'model_key' => (string) $fallback['model_key']];
                    }
                    $last = null;
                    foreach (array_slice($models, 0, 1 + max(0, (int) ($route['max_retries'] ?? 1))) as $candidate) {
                        $model = $candidate['model_key'];
                        try {
                            $response = Http::timeout(app(AiProviderConfiguration::class)->timeoutSeconds('openai'))->withToken($apiKey)->post('https://api.openai.com/v1/audio/speech', [
                                'model' => $model,
                                'input' => $text,
                                'voice' => $voice,
                                'response_format' => 'mp3',
                            ]);
                            if (!$response->successful()) throw new AiProviderRequestException('OpenAI text-to-speech', $response->status());
                            $fileName = app(IncomingMediaStorage::class)->store($response->body(), 'mp3');
                            return ['content' => '', 'audio_path' => $fileName, 'provider' => 'openai', 'model' => $model, 'usage' => ['input_tokens' => 0, 'output_tokens' => 0, 'audio_output_chars' => mb_strlen($text)]];
                        } catch (AiProviderRequestException $exception) {
                            app(AiGateway::class)->reportProviderFailure('openai', $model, $exception);
                            $last = $exception;
                            if (!$exception->isRetryableUnbilled()) break;
                        }
                    }
                    throw $last ?? new \RuntimeException('No eligible text-to-speech model is available.');
                },
                ['modality' => 'audio_generation', 'audio_output_chars' => mb_strlen($text), 'routing_source' => $route['source'], 'routing_rule_id' => $route['rule_id'], 'fallback_model_ids' => $route['fallback_model_ids'] ?? []],
                $operationId,
            );

            // Keep generated speech private and expose only an expiring signed URL.
            $fileName = (string) ($result['audio_path'] ?? '');
            if ($fileName === '') throw new \RuntimeException('Text-to-speech returned no stored audio file.');
            $signedUrl = URL::temporarySignedRoute('media.tts.show', now()->addDays(7), [
                'mediaPath' => $fileName,
            ]);

            Log::info('TTS audio generated', [
                'voice' => $voice,
                'text_length' => mb_strlen($text),
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
        return app(AiProviderConfiguration::class)->isAvailable('openai');
    }
}
