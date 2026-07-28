<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Channel;
use App\Models\Message;
use App\Models\Conversation;
use Google\Client as GoogleClient;
use Google\Service\Gmail;

class ResyncGmailHtml extends Command
{
    protected $signature = 'gmail:resync-html {--channel-id= : Specific channel ID to resync}';
    protected $description = 'Resync Gmail messages to extract HTML content';

    public function handle()
    {
        $channelId = $this->option('channel-id');
        
        if ($channelId) {
            $channel = Channel::find($channelId);
        } else {
            $channel = Channel::where('type', 'gmail')->where('status', 'connected')->first();
        }

        if (!$channel) {
            $this->error('No connected Gmail channel found.');
            return 1;
        }

        $this->info("Processing Gmail channel: " . $channel->page_name);

        try {
            $client = $this->getAuthenticatedClient($channel);
            if (!$client) {
                $this->error('Failed to get authenticated Gmail client.');
                return 1;
            }

            $gmail = new Gmail($client);
            $updatedCount = 0;

            // Get messages that don't have HTML but have Gmail message ID
            $messages = Message::where('gmail_message_id', '!=', null)
                ->where('content_html', null)
                ->limit(50) // Process in batches
                ->get();

            $this->info("Found " . $messages->count() . " messages to resync");

            foreach ($messages as $message) {
                try {
                    $full = $gmail->users_messages->get('me', $message->gmail_message_id, ['format' => 'full']);
                    $bodyHtml = $this->extractHtmlBody($full->getPayload());
                    
                    if ($bodyHtml) {
                        $message->update(['content_html' => $bodyHtml]);
                        $updatedCount++;
                        $this->info("Updated message ID: " . $message->id);
                    }
                } catch (\Exception $e) {
                    $this->warn("Failed to process message " . $message->id . ": " . $e->getMessage());
                }
            }

            $this->info("Successfully updated " . $updatedCount . " messages with HTML content");
            return 0;

        } catch (\Exception $e) {
            $this->error('Error: ' . $e->getMessage());
            return 1;
        }
    }

    private function getAuthenticatedClient(Channel $channel): ?GoogleClient
    {
        try {
            $tokenData = json_decode(decrypt($channel->getRawOriginal('access_token')), true);
            $client = new GoogleClient();
            $client->setClientId(env('GOOGLE_CLIENT_ID'));
            $client->setClientSecret(env('GOOGLE_CLIENT_SECRET'));
            $client->setRedirectUri(env('GOOGLE_REDIRECT_URI'));
            $client->addScope(Gmail::GMAIL_READONLY);
            $client->setAccessType('offline');
            $client->setAccessToken($tokenData);

            if ($client->isAccessTokenExpired()) {
                $refreshToken = $channel->refresh_token ?? ($tokenData['refresh_token'] ?? null);
                if (!$refreshToken) {
                    return null;
                }
                try {
                    $refreshToken = decrypt($refreshToken);
                } catch (\Exception $e) {
                    // Already plain string
                }
                $client->fetchAccessTokenWithRefreshToken($refreshToken);
                $newToken = $client->getAccessToken();

                Channel::where('id', $channel->id)->update([
                    'access_token' => encrypt(json_encode($newToken)),
                    'updated_at' => now(),
                ]);
            }

            return $client;
        } catch (\Exception $e) {
            return null;
        }
    }

    private function extractHtmlBody($payload): string
    {
        $found = $this->findPartByMimeType($payload, 'text/html');
        if ($found !== null && !empty($found)) {
            return $found;
        }

        if ($payload->getMimeType() === 'text/html') {
            $data = $payload->getBody()->getData();
            if ($data) {
                $decoded = $this->decodePartData($data);
                if (!empty($decoded)) {
                    return $decoded;
                }
            }
        }

        if (strpos($payload->getMimeType(), 'multipart') === 0) {
            foreach ($payload->getParts() ?? [] as $part) {
                if ($part->getMimeType() === 'text/html') {
                    $data = $part->getBody()->getData();
                    if ($data) {
                        $decoded = $this->decodePartData($data);
                        if (!empty($decoded)) {
                            return $decoded;
                        }
                    }
                }
            }
        }

        return '';
    }

    private function findPartByMimeType($payload, string $mimeType): ?string
    {
        if ($payload->getMimeType() === $mimeType) {
            $data = $payload->getBody()->getData();
            if ($data) {
                return $this->decodePartData($data);
            }
        }

        foreach ($payload->getParts() ?? [] as $part) {
            $found = $this->findPartByMimeType($part, $mimeType);
            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    private function decodePartData(string $data): string
    {
        return quoted_printable_decode(base64_decode(strtr($data, '-_', '+/')));
    }
}