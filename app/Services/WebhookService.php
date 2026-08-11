<?php

namespace App\Services;

use App\Models\Webhook;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WebhookService
{
    /**
     * Trigger webhooks for an event
     */
    public function triggerEvent(int $businessId, string $event, array $payload): void
    {
        $webhooks = Webhook::where('business_id', $businessId)
            ->active()
            ->forEvent($event)
            ->get();

        foreach ($webhooks as $webhook) {
            $this->sendWebhook($webhook, $event, $payload);
        }
    }

    /**
     * Send webhook to external URL
     */
    private function sendWebhook(Webhook $webhook, string $event, array $payload): void
    {
        try {
            $webhookPayload = array_merge($payload, [
                'event' => $event,
                'timestamp' => now()->toISOString(),
                'webhook_id' => $webhook->id,
            ]);

            $response = Http::post($webhook->url, $webhookPayload, [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'X-Webhook-Signature' => $this->generateSignature($webhookPayload, $webhook->secret),
                ],
                'timeout' => 10,
            ]);

            if ($response->successful()) {
                $webhook->increment('success_count');
                $webhook->update(['last_triggered_at' => now()]);
                
                Log::info('Webhook sent successfully', [
                    'webhook_id' => $webhook->id,
                    'event' => $event,
                ]);
            } else {
                $webhook->increment('failure_count');
                
                Log::warning('Webhook failed', [
                    'webhook_id' => $webhook->id,
                    'event' => $event,
                    'status' => $response->status(),
                ]);
            }
        } catch (\Exception $e) {
            $webhook->increment('failure_count');
            
            Log::error('Webhook exception', [
                'webhook_id' => $webhook->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Generate webhook signature
     */
    private function generateSignature(array $payload, ?string $secret): string
    {
        if (!$secret) {
            return '';
        }

        return hash_hmac('sha256', json_encode($payload), $secret);
    }

    /**
     * Verify webhook signature
     */
    public function verifySignature(array $payload, string $signature, string $secret): bool
    {
        $expectedSignature = $this->generateSignature($payload, $secret);
        return hash_equals($expectedSignature, $signature);
    }
}
