<?php

namespace App\Services;

use App\Jobs\DeliverWebhook;
use App\Models\Webhook;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\Client\Response;
use RuntimeException;

class WebhookService
{
    private const MAX_PAYLOAD_BYTES = 1_048_576;

    public function triggerEvent(int $businessId, string $event, array $payload): void
    {
        $webhooks = Webhook::where('business_id', $businessId)
            ->active()
            ->forEvent($event)
            ->get(['id']);

        foreach ($webhooks as $webhook) {
            DeliverWebhook::dispatch($webhook->id, $event, $payload);
        }
    }

    /** Deliver a queued webhook after revalidating its destination and signature. */
    public function deliver(Webhook $webhook, string $event, array $payload): void
    {
        try {
            if (!$webhook->secret) {
                throw new RuntimeException('Webhook signing secret is not configured.');
            }

            $webhookPayload = array_merge($payload, [
                'event' => $event,
                'timestamp' => now()->toISOString(),
                'webhook_id' => $webhook->id,
            ]);
            $response = $this->postSignedJson($webhook->url, $webhookPayload, $webhook->secret);

            if ($response->successful()) {
                $webhook->increment('success_count');
                $webhook->update(['last_triggered_at' => now()]);
                Log::info('Webhook sent successfully', ['webhook_id' => $webhook->id, 'event' => $event]);
                return;
            }

            $webhook->increment('failure_count');
            Log::warning('Webhook failed', [
                'webhook_id' => $webhook->id,
                'event' => $event,
                'status' => $response->status(),
            ]);
        } catch (\Throwable $e) {
            $webhook->increment('failure_count');
            Log::error('Webhook delivery rejected or failed', [
                'webhook_id' => $webhook->id,
                'event' => $event,
                'exception_type' => get_class($e),
            ]);
        }
    }

    public function verifySignature(array $payload, string $signature, string $secret): bool
    {
        $expectedSignature = hash_hmac(
            'sha256',
            json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            $secret
        );

        return hash_equals($expectedSignature, $signature);
    }

    /** Validate and resolve a destination before persisting or queueing it. */
    public function validateDestination(string $url): void
    {
        $this->resolvePublicDestination($url);
    }

    public function postSignedJson(string $url, array $payload, string $secret): Response
    {
        if ($secret === '') {
            throw new RuntimeException('Webhook signing secret is not configured.');
        }

        [$resolveAddress, $host, $port] = $this->resolvePublicDestination($url);
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        if (strlen($body) > self::MAX_PAYLOAD_BYTES) {
            throw new RuntimeException('Webhook payload exceeds the 1 MiB delivery limit.');
        }

        $signature = hash_hmac('sha256', $body, $secret);
        $resolveIp = str_contains($resolveAddress, ':') ? '[' . $resolveAddress . ']' : $resolveAddress;
        $options = ['allow_redirects' => false, 'proxy' => null];
        if (!filter_var($host, FILTER_VALIDATE_IP)) {
            $options['curl'] = [CURLOPT_RESOLVE => ["{$host}:{$port}:{$resolveIp}"]];
        }

        return Http::withHeaders([
            'Content-Type' => 'application/json',
            'X-Webhook-Signature' => $signature,
        ])
            ->timeout(10)
            ->connectTimeout(3)
            ->withOptions($options)
            ->withBody($body, 'application/json')
            ->post($url);
    }

    /** @return array{0:string,1:string,2:int} pinned IP, host, port */
    private function resolvePublicDestination(string $url): array
    {
        $parts = parse_url($url);
        if (!$parts || strtolower($parts['scheme'] ?? '') !== 'https'
            || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
            throw new RuntimeException('Webhook destination must be an HTTPS URL without embedded credentials.');
        }

        $host = strtolower($parts['host']);
        if (str_ends_with($host, '.')) {
            throw new RuntimeException('Webhook destination host cannot end with a dot.');
        }
        $port = (int) ($parts['port'] ?? 443);
        if ($port < 1 || $port > 65535) {
            throw new RuntimeException('Webhook destination port is invalid.');
        }

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $addresses = [$host];
        } else {
            if (in_array($host, ['localhost'], true) || str_ends_with($host, '.localhost')
                || str_ends_with($host, '.local') || str_ends_with($host, '.internal')) {
                throw new RuntimeException('Webhook destination host is not public.');
            }

            $records = dns_get_record($host, DNS_A | DNS_AAAA);
            $addresses = [];
            foreach ($records ?: [] as $record) {
                if (!empty($record['ip'])) {
                    $addresses[] = $record['ip'];
                }
                if (!empty($record['ipv6'])) {
                    $addresses[] = $record['ipv6'];
                }
            }
        }

        $addresses = array_values(array_unique($addresses));
        if (!$addresses) {
            throw new RuntimeException('Webhook destination host did not resolve to a public address.');
        }

        foreach ($addresses as $address) {
            if (!filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                throw new RuntimeException('Webhook destination resolves to a private or reserved address.');
            }
        }

        if (!defined('CURLOPT_RESOLVE')) {
            throw new RuntimeException('The HTTP runtime cannot pin webhook DNS resolution safely.');
        }

        return [$addresses[0], $host, $port];
    }
}
