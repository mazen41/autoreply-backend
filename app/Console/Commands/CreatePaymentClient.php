<?php

namespace App\Console\Commands;

use App\Models\PaymentClient;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class CreatePaymentClient extends Command
{
    protected $signature = 'payment:client:create {slug} {name} {backend_url} {frontend_url}';
    protected $description = 'Register a website as a central payment client and display its credentials once.';

    public function handle(): int
    {
        $slug = (string) $this->argument('slug');
        $backendUrl = rtrim((string) $this->argument('backend_url'), '/');
        $frontendUrl = rtrim((string) $this->argument('frontend_url'), '/');

        if (!preg_match('/^[a-z0-9-]{2,80}$/', $slug)
            || !filter_var($backendUrl, FILTER_VALIDATE_URL)
            || !filter_var($frontendUrl, FILTER_VALIDATE_URL)
            || parse_url($backendUrl, PHP_URL_SCHEME) !== 'https'
            || parse_url($frontendUrl, PHP_URL_SCHEME) !== 'https') {
            $this->error('Use a lowercase slug and HTTPS backend/frontend URLs.');
            return self::FAILURE;
        }

        if (PaymentClient::query()->where('slug', $slug)->exists()) {
            $this->error('A payment client with this slug already exists.');
            return self::FAILURE;
        }

        $apiKey = bin2hex(random_bytes(32));
        $hmacSecret = bin2hex(random_bytes(32));
        $notificationSecret = bin2hex(random_bytes(32));
        $client = PaymentClient::query()->create([
            'public_id' => (string) Str::uuid(),
            'slug' => $slug,
            'name' => (string) $this->argument('name'),
            'api_key_hash' => hash('sha256', $apiKey),
            'hmac_secret' => $hmacSecret,
            'notification_secret' => $notificationSecret,
            'backend_url' => $backendUrl,
            'frontend_url' => $frontendUrl,
            'is_active' => true,
        ]);

        $this->warn('Copy these credentials to the client backend secret store now. They are shown only once.');
        $this->table(['Credential', 'Value'], [
            ['Client ID', $client->public_id],
            ['API key', $apiKey],
            ['HMAC secret', $hmacSecret],
            ['Notification secret', $notificationSecret],
        ]);

        return self::SUCCESS;
    }
}
