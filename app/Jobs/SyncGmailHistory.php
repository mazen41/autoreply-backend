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
use Illuminate\Support\Facades\Log;
use Google\Service\Gmail;
use Google\Service\Gmail\Message as GmailMessage;

class SyncGmailHistory implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;
    public $backoff = 30;

    public function __construct(public int $channelId)
    {
    }

    public function handle(): void
    {
        Log::info('SyncGmailHistory: starting sync', ['channel_id' => $this->channelId]);

        $channel = Channel::find($this->channelId);
        if (!$channel || $channel->type !== 'gmail') {
            Log::warning('SyncGmailHistory: channel not found or not Gmail', ['channel_id' => $this->channelId]);
            return;
        }

        $gmailCtrl = new GmailController();
        $client = $gmailCtrl->getAuthenticatedClient($channel);

        if (!$client) {
            Log::error('SyncGmailHistory: could not authenticate client', ['channel_id' => $this->channelId]);
            return;
        }

        try {
            $gmail = new Gmail($client);
            
            // Fetch last 30 emails (both read and unread, excluding sent by me)
            $results = $gmail->users_messages->listUsersMessages('me', [
                'labelIds'   => ['INBOX'],
                'q'          => '-from:me',
                'maxResults' => 30,
            ]);

            $msgs = $results->getMessages() ?? [];
            $imported = 0;

            foreach ($msgs as $msgRef) {
                $msgId = $msgRef->getId();

                // Skip if already exists in DB
                if (Message::where('gmail_message_id', $msgId)->exists()) {
                    continue;
                }

                $full = $gmail->users_messages->get('me', $msgId, ['format' => 'full']);
                $headers = collect($full->getPayload()->getHeaders())->keyBy('name');

                $from = $headers->get('From')?->getValue() ?? 'Unknown';
                $subject = $headers->get('Subject')?->getValue() ?? '(no subject)';
                $gmailMsgId = $headers->get('Message-ID')?->getValue() ?? $msgId;
                $threadId = $full->getThreadId();
                $sentAt = \Carbon\Carbon::createFromTimestampMs($full->getInternalDate());

                preg_match('/<(.+?)>/', $from, $m);
                $senderEmail = $m[1] ?? $from;
                $senderName = trim(preg_replace('/<.+?>/', '', $from)) ?: $senderEmail;

                $body = $gmailCtrl->extractBody($full->getPayload());

                if (empty(trim($body))) {
                    continue;
                }

                $conversation = Conversation::firstOrCreate(
                    ['channel_id' => $channel->id, 'sender_id' => $threadId],
                    [
                        'business_id'     => $channel->business_id,
                        'sender_name'     => $senderName,
                        'sender_email'    => $senderEmail,
                        'subject'         => $subject,
                        'status'          => 'open',
                        'last_message_at' => $sentAt,
                    ]
                );

                if ($sentAt->gt($conversation->last_message_at)) {
                    $conversation->update(['last_message_at' => $sentAt]);
                }

                // Create message without auto-reply dispatch
                Message::create([
                    'conversation_id'  => $conversation->id,
                    'content'          => $body,
                    'direction'        => 'inbound',
                    'is_ai'            => false,
                    'status'           => 'received',
                    'gmail_message_id' => $gmailMsgId,
                    'created_at'       => $sentAt,
                    'updated_at'       => $sentAt,
                ]);

                $imported++;
            }

            Log::info('SyncGmailHistory: completed sync', [
                'channel_id' => $this->channelId,
                'imported' => $imported,
            ]);

        } catch (\Exception $e) {
            Log::error('SyncGmailHistory exception', [
                'error' => $e->getMessage(),
                'channel_id' => $this->channelId,
            ]);
        }
    }
}
