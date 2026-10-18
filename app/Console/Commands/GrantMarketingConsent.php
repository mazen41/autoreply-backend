<?php

namespace App\Console\Commands;

use App\Models\ContactConsent;
use App\Models\Conversation;
use App\Models\Suppression;
use App\Services\ConsentGate;
use Illuminate\Console\Command;

class GrantMarketingConsent extends Command
{
    protected $signature = 'consent:grant {conversationId? : Specific conversation ID to grant consent for} {--all : Grant marketing consent to all conversations} {--business= : Business ID filter}';

    protected $description = 'Grant marketing consent to conversations so they can receive campaigns';

    public function handle(ConsentGate $consentGate): int
    {
        $conversationId = $this->argument('conversationId');
        $grantAll = (bool) $this->option('all');
        $businessId = $this->option('business');

        if (!$conversationId && !$grantAll) {
            $this->error('Please specify a conversation ID (e.g. php artisan consent:grant 17) or use --all.');
            return self::FAILURE;
        }

        $query = Conversation::with('channel');
        if ($conversationId) {
            $query->where('id', (int) $conversationId);
        }
        if ($businessId) {
            $query->where('business_id', (int) $businessId);
        }

        $conversations = $query->get();

        if ($conversations->isEmpty()) {
            $this->error($conversationId ? "Conversation #{$conversationId} not found." : 'No conversations found.');
            return self::FAILURE;
        }

        $count = 0;
        foreach ($conversations as $conversation) {
            $channel = $conversation->channel;
            if (!$channel) {
                $this->warn("Conversation #{$conversation->id} has no associated channel, skipped.");
                continue;
            }

            $channelType = strtolower((string) $channel->type);
            $consentChannel = $channelType === 'gmail' ? 'email' : $channelType;
            $contact = $channelType === 'gmail'
                ? (string) ($conversation->sender_email ?? '')
                : (string) ($conversation->sender_id ?? '');

            if ($contact === '') {
                $this->warn("Conversation #{$conversation->id} has empty contact identifier, skipped.");
                continue;
            }

            $identifier = $consentGate->normalizeIdentifier($contact, $consentChannel);

            ContactConsent::updateOrCreate(
                [
                    'business_id' => $conversation->business_id,
                    'channel' => $consentChannel,
                    'contact_identifier' => $identifier,
                    'scope' => 'marketing',
                ],
                [
                    'status' => 'opted_in',
                    'source' => 'admin_command',
                    'captured_at' => now(),
                    'evidence' => 'Granted via artisan consent:grant',
                ]
            );

            // Remove any suppression
            Suppression::where('business_id', $conversation->business_id)
                ->where('channel', $consentChannel)
                ->where('contact_identifier', $identifier)
                ->delete();

            $count++;
            $this->info("✓ Granted marketing consent for conversation #{$conversation->id} ({$contact}) on channel {$consentChannel}.");
        }

        $this->info("Successfully granted marketing consent to {$count} conversation(s).");
        return self::SUCCESS;
    }
}
