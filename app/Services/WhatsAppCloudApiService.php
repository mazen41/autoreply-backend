<?php

namespace App\Services;

use App\Models\Channel;
use App\Models\WhatsAppCloudTemplate;
use App\Services\IncomingMediaStorage;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WhatsAppCloudApiService
{
    public function sendText(Channel $channel, string $recipient, string $text): array
    {
        return $this->send($channel, [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $recipient,
            'type' => 'text',
            'text' => ['preview_url' => false, 'body' => $text],
        ]);
    }

    public function sendImage(Channel $channel, string $recipient, string $imageUrl, string $caption = ''): array
    {
        $image = ['link' => $imageUrl];
        if ($caption !== '') {
            $image['caption'] = $caption;
        }

        return $this->send($channel, [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $recipient,
            'type' => 'image',
            'image' => $image,
        ]);
    }

    public function downloadInboundMedia(Channel $channel, string $mediaId, string $mediaType): array
    {
        $failed = ['type' => $mediaType, 'storage_status' => 'failed'];
        try {
            $metadata = $this->request($channel)->get($this->url(rawurlencode($mediaId)));
            $mediaUrl = $metadata->json('url');
            $mimeType = (string) ($metadata->json('mime_type') ?? 'application/octet-stream');
            $fileSize = (int) ($metadata->json('file_size') ?? 0);
            if (!$metadata->successful() || !is_string($mediaUrl) || $fileSize > 50 * 1024 * 1024) {
                $this->logProviderFailure($channel, $metadata, 'inbound_media_metadata');
                return $failed;
            }

            $host = strtolower((string) parse_url($mediaUrl, PHP_URL_HOST));
            $trustedHost = $host === 'lookaside.fbsbx.com'
                || str_ends_with($host, '.fbcdn.net')
                || str_ends_with($host, '.fbsbx.com');
            if (strtolower((string) parse_url($mediaUrl, PHP_URL_SCHEME)) !== 'https' || !$trustedHost) {
                return $failed;
            }

            $download = Http::withToken($channel->access_token)
                ->timeout(20)
                ->withoutRedirecting()
                ->get($mediaUrl);
            if (!$download->successful()) {
                $this->logProviderFailure($channel, $download, 'inbound_media_download');
                return $failed;
            }
            if (strlen($download->body()) > 50 * 1024 * 1024) {
                return $failed;
            }

            $extension = match (strtolower(trim(explode(';', $mimeType)[0]))) {
                'image/jpeg' => 'jpg',
                'image/png' => 'png',
                'image/webp' => 'webp',
                'audio/ogg' => 'ogg',
                'audio/mpeg' => 'mp3',
                'audio/mp4' => 'm4a',
                'video/mp4' => 'mp4',
                'application/pdf' => 'pdf',
                default => 'bin',
            };
            $storage = app(IncomingMediaStorage::class);
            $body = $download->body();
            $path = $storage->store($body, $extension);

            return [
                'type' => $mediaType,
                'url' => $path,
                'storage_disk' => $storage->diskName(),
                'storage_status' => 'stored',
                'mime_type' => $mimeType,
                'file_size' => strlen($body),
            ];
        } catch (\Throwable $exception) {
            Log::warning('WhatsApp Cloud inbound media could not be stored', [
                'channel_id' => $channel->id,
                'media_type' => $mediaType,
                'error_class' => get_class($exception),
            ]);
            return $failed;
        }
    }

    public function sendTemplate(
        Channel $channel,
        string $recipient,
        string $templateName,
        string $language,
        array $components = []
    ): array {
        $approvedTemplate = WhatsAppCloudTemplate::query()
            ->where('business_id', $channel->business_id)
            ->where('channel_id', $channel->id)
            ->where('name', $templateName)
            ->where('language', $language)
            ->where('status', 'APPROVED')
            ->first();

        if (!$approvedTemplate) {
            return ['success' => false, 'reason' => 'template_not_approved'];
        }

        return $this->send($channel, [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $recipient,
            'type' => 'template',
            'template' => [
                'name' => $approvedTemplate->name,
                'language' => ['code' => $approvedTemplate->language],
                'components' => $components,
            ],
        ]);
    }

    public function createTemplate(Channel $channel, array $template): array
    {
        $wabaId = $this->wabaId($channel);
        $response = $this->request($channel)
            ->post($this->url("{$wabaId}/message_templates"), $template);

        return $this->result($response, $channel, 'template_create');
    }

    public function subscribeWebhook(Channel $channel): array
    {
        $wabaId = $this->wabaId($channel);
        $response = $this->request($channel)->post($this->url("{$wabaId}/subscribed_apps"));
        return $this->result($response, $channel, 'webhook_subscribe');
    }

    public function syncTemplates(Channel $channel): array
    {
        $wabaId = $this->wabaId($channel);
        $templates = [];
        $after = null;
        for ($page = 0; $page < 20; $page++) {
            $params = [
                'fields' => 'id,name,language,category,status,components,rejected_reason',
                'limit' => 100,
            ];
            if ($after) {
                $params['after'] = $after;
            }

            $response = $this->request($channel)->get($this->url("{$wabaId}/message_templates"), $params);
            if (!$response->successful()) {
                $this->logProviderFailure($channel, $response, 'template_sync');
                return ['success' => false, 'templates' => []];
            }

            $pageTemplates = $response->json('data', []);
            $templates = array_merge($templates, $pageTemplates);
            $nextAfter = $response->json('paging.cursors.after');
            if (!$nextAfter || empty($pageTemplates) || $nextAfter === $after) {
                break;
            }
            $after = $nextAfter;
        }

        foreach ($templates as $template) {
            if (empty($template['name']) || empty($template['language'])) {
                continue;
            }

            WhatsAppCloudTemplate::updateOrCreate(
                [
                    'channel_id' => $channel->id,
                    'name' => $template['name'],
                    'language' => $template['language'],
                ],
                [
                    'business_id' => $channel->business_id,
                    'provider_template_id' => $template['id'] ?? null,
                    'category' => $template['category'] ?? 'UTILITY',
                    'components' => $template['components'] ?? [],
                    'status' => strtoupper($template['status'] ?? 'PENDING'),
                    'rejection_reason' => $template['rejected_reason'] ?? null,
                ]
            );
        }

        return ['success' => true, 'templates' => $templates];
    }

    private function send(Channel $channel, array $payload): array
    {
        $response = $this->request($channel)
            ->post($this->url($channel->page_id . '/messages'), $payload);

        return $this->result($response, $channel, 'message_send');
    }

    private function request(Channel $channel)
    {
        if (!$this->isCloudChannel($channel) || !$channel->access_token || !$channel->page_id) {
            throw new \InvalidArgumentException('A connected WhatsApp Cloud API channel is required.');
        }

        return Http::acceptJson()
            ->withToken($channel->access_token)
            ->timeout(15);
    }

    private function result(Response $response, Channel $channel, string $operation): array
    {
        if (!$response->successful()) {
            $this->logProviderFailure($channel, $response, $operation);
            return [
                'success' => false,
                'reason' => (string) ($response->json('error.code') ?? 'provider_rejected'),
            ];
        }

        return [
            'success' => true,
            'message_id' => $response->json('messages.0.id'),
            'data' => $response->json(),
        ];
    }

    private function logProviderFailure(Channel $channel, Response $response, string $operation): void
    {
        Log::warning('WhatsApp Cloud API request failed', [
            'channel_id' => $channel->id,
            'operation' => $operation,
            'http_status' => $response->status(),
            'provider_code' => $response->json('error.code'),
        ]);
    }

    private function isCloudChannel(Channel $channel): bool
    {
        return $channel->type === 'whatsapp'
            && ($channel->metadata['whatsapp_provider'] ?? null) === 'cloud'
            && $channel->status === 'connected';
    }

    private function wabaId(Channel $channel): string
    {
        if (!$this->isCloudChannel($channel)) {
            throw new \InvalidArgumentException('A connected WhatsApp Cloud API channel is required.');
        }

        $wabaId = (string) ($channel->metadata['whatsapp_business_account_id'] ?? '');
        if ($wabaId === '') {
            throw new \InvalidArgumentException('WhatsApp Business Account ID is missing.');
        }

        return $wabaId;
    }

    private function url(string $path): string
    {
        $version = trim((string) config('services.meta.graph_version', 'v26.0'));
        return 'https://graph.facebook.com/' . trim($version, '/') . '/' . ltrim($path, '/');
    }
}
