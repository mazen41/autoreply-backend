<?php

namespace App\Jobs;

use App\Models\Channel;
use App\Models\Conversation;
use App\Models\Message;
use App\Http\Controllers\GmailController;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Google\Service\Gmail;
use Google\Service\Gmail\Message as GmailMessage;

class PollGmailInbox implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(): void
    {
        $channels = Channel::where('type', 'gmail')
            ->where('status', 'connected')
            ->get();

        // Backfill business_id on any channel that's missing it
        foreach ($channels as $channel) {
            if (!$channel->business_id) {
                $business = \App\Models\BusinessProfile::where('user_id', $channel->user_id)->first();
                if ($business) {
                    $channel->update(['business_id' => $business->id]);
                    $channel->business_id = $business->id;
                }
            }
        }

        // Only poll channels that have a resolvable business
        $channels = $channels->filter(fn($c) => !empty($c->business_id));

        Log::info('Gmail poll: checking ' . $channels->count() . ' channel(s)');

        foreach ($channels as $channel) {
            $this->pollChannel($channel);
        }
    }

    private function pollChannel(Channel $channel): void
    {
        $gmailCtrl = new GmailController();
        $client    = $gmailCtrl->getAuthenticatedClient($channel);

        if (!$client) {
            Log::error('Gmail poll: could not get client, marking as disconnected', ['channel_id' => $channel->id]);
            $channel->update(['status' => 'disconnected']);
            return;
        }

        try {
            $gmail   = new Gmail($client);
            $results = $gmail->users_messages->listUsersMessages('me', [
                'q'          => 'is:unread in:inbox -from:me',
                'maxResults' => 10,
            ]);

            $messages = $results->getMessages();
            if (empty($messages)) {
                Log::info('Gmail poll: no new emails', ['channel_id' => $channel->id]);
                return;
            }

            foreach ($messages as $msgRef) {
                $msgId  = $msgRef->getId();
                $exists = Message::where('gmail_message_id', $msgId)->exists();
                if ($exists) continue;

                $full = $gmail->users_messages->get('me', $msgId, ['format' => 'full']);
                $this->processEmail($channel, $gmail, $full);

                // Mark as read
                $modify = new \Google\Service\Gmail\ModifyMessageRequest();
                $modify->setRemoveLabelIds(['UNREAD']);
                $gmail->users_messages->modify('me', $msgId, $modify);
            }

        } catch (\Exception $e) {
            Log::error('Gmail poll exception', ['error' => $e->getMessage(), 'channel_id' => $channel->id]);
        }
    }

    private function processEmail(Channel $channel, Gmail $gmail, GmailMessage $msg): void
    {
        $headers  = collect($msg->getPayload()->getHeaders());
        $subject  = $headers->firstWhere('name', 'Subject')?->getValue() ?? '(no subject)';
        $from     = $headers->firstWhere('name', 'From')?->getValue() ?? 'unknown';
        $threadId = $msg->getThreadId();
        $msgId    = $msg->getId();

        preg_match('/<(.+?)>/', $from, $m);
        $senderEmail = $m[1] ?? $from;

        $body = $this->extractBody($msg->getPayload());

        if (empty(trim($body))) {
            Log::info('Gmail: skipping email with no text body', ['msg_id' => $msgId]);
            return;
        }

        Log::info('Gmail: new email received', [
            'from'    => $senderEmail,
            'subject' => $subject,
        ]);

        $conversation = Conversation::firstOrCreate(
            ['channel_id' => $channel->id, 'sender_id' => $threadId],
            [
                'business_id'     => $channel->business_id,
                'status'          => 'open',
                'last_message_at' => now(),
                'sender_email'    => $senderEmail,
                'subject'         => $subject,
            ]
        );
        // Always sync sender_email — it's only set on first create above,
        // so existing conversations from before this fix have it null.
        $conversation->update([
            'last_message_at' => now(),
            'sender_email'    => $senderEmail,
        ]);

        $message = Message::create([
            'conversation_id'  => $conversation->id,
            'content'          => $body,
            'direction'        => 'inbound',
            'is_ai'            => false,
            'status'           => 'received',
            'gmail_message_id' => $msgId,
        ]);

        // Implement message debounce for Gmail polling
        $debounceKey = "debounce:conversation:{$conversation->id}";
        $debounceWindow = 10; // 10 seconds debounce window
        
        if (Cache::has($debounceKey)) {
            Log::info('Gmail polling message debounced - AI reply skipped', [
                'conversation_id' => $conversation->id,
                'message_id' => $message->id
            ]);
        } else {
            ProcessAutoReply::dispatch($message->id);
            Cache::put($debounceKey, true, $debounceWindow);
            Log::info('Gmail: ProcessAutoReply job dispatched', ['message_id' => $message->id]);
        }
    }

    private function extractBody(\Google\Service\Gmail\MessagePart $payload): string
    {
        $mimeType = $payload->getMimeType();
        $data     = $payload->getBody()->getData();

        if ($data && $mimeType === 'text/plain') {
            return base64_decode(strtr($data, '-_', '+/'));
        }
        if ($data && $mimeType === 'text/html') {
            return strip_tags(base64_decode(strtr($data, '-_', '+/')));
        }

        foreach ($payload->getParts() ?? [] as $part) {
            $text = $this->extractBody($part);
            if (!empty(trim($text))) return $text;
        }

        return '';
    }

    private function sendEmailReply(Gmail $gmail, string $to, string $subject, string $threadId, string $inReplyToId, string $body): void
    {
        $raw  = "To: {$to}\r\n";
        $raw .= "Subject: Re: {$subject}\r\n";
        $raw .= "In-Reply-To: {$inReplyToId}\r\n";
        $raw .= "References: {$inReplyToId}\r\n";
        $raw .= "Content-Type: text/plain; charset=utf-8\r\n\r\n";
        $raw .= $body;

        $encoded = rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');

        $message = new GmailMessage();
        $message->setRaw($encoded);
        $message->setThreadId($threadId);

        $gmail->users_messages->send('me', $message);
    }

    private function getGeminiReply(string $messageText, string $subject, Channel $channel): ?string
    {
        $apiKey   = env('GEMINI_API_KEY');
        $model    = env('GEMINI_MODEL', 'gemini-2.5-flash');
        $business = $channel->business;

        $prompt = $business
            ? "You are a customer service assistant for {$business->business_name}. Reply to this email professionally and concisely.\n\nSubject: {$subject}\n\nEmail:\n{$messageText}\n\nReply:"
            : "You are a helpful customer service assistant. Reply to this email politely in the same language used. Keep it short.\n\nSubject: {$subject}\n\nEmail:\n{$messageText}\n\nReply:";

        try {
            $response = Http::timeout(20)
                ->post(
                    "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$apiKey}",
                    [
                        'contents'         => [['parts' => [['text' => $prompt]]]],
                        'generationConfig' => ['maxOutputTokens' => 400, 'temperature' => 0.7],
                    ]
                );

            return $response->json()['candidates'][0]['content']['parts'][0]['text'] ?? null;
        } catch (\Exception $e) {
            Log::error('Gemini Gmail exception', ['error' => $e->getMessage()]);
            return null;
        }
    }
}