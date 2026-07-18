<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Conversation;
use App\Models\WhatsAppMessage;

class FixWhatsAppContactNames extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'fix:whatsapp-names';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Fix WhatsApp contact names by updating conversations with names from WhatsApp messages';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Starting to fix WhatsApp contact names...');

        // Find conversations where sender_name looks like a phone number or is empty
        $conversations = Conversation::whereHas('channel', function ($q) {
            $q->where('type', 'whatsapp');
        })
        ->where(function ($q) {
            $q->where('sender_name', 'like', '+%')
              ->orWhere('sender_name', 'like', '0%')
              ->orWhereNull('sender_name')
              ->orWhere('sender_name', '');
        })
        ->get();

        $this->info("Found {$conversations->count()} conversations to check");

        $updated = 0;

        foreach ($conversations as $conversation) {
            // Look for WhatsApp messages with better names
            $whatsappMessage = WhatsAppMessage::where('from_phone', $conversation->sender_id)
                ->where(function ($q) {
                    // Check from_name field
                    $q->whereNotNull('from_name')
                      ->where('from_name', '!=', '')
                      ->where('from_name', '!=', '.')
                      ->where(function ($q) {
                          $q->where('from_name', 'not like', '+%')
                            ->where('from_name', 'not like', '0%');
                      });
                })
                ->orderBy('sent_at', 'desc')
                ->first();

            $realName = null;

            if ($whatsappMessage && $whatsappMessage->from_name) {
                $realName = $whatsappMessage->from_name;
            } else {
                // If no good from_name, check metadata for pushName
                $whatsappMessageWithMeta = WhatsAppMessage::where('from_phone', $conversation->sender_id)
                    ->whereNotNull('metadata')
                    ->orderBy('sent_at', 'desc')
                    ->first();

                if ($whatsappMessageWithMeta) {
                    $metadata = $whatsappMessageWithMeta->metadata;
                    if (isset($metadata['pushName']) && $metadata['pushName'] && $metadata['pushName'] !== '.') {
                        $realName = $metadata['pushName'];
                        $this->info("Found name in metadata: {$realName}");
                    }
                }
            }

            if ($realName) {
                $oldName = $conversation->sender_name;
                $conversation->update(['sender_name' => $realName]);
                
                $this->info("Updated conversation {$conversation->id}: '{$oldName}' -> '{$realName}'");
                $updated++;
            }
        }

        $this->info("Successfully updated {$updated} conversations");
        $this->info('Done!');
        
        return Command::SUCCESS;
    }
}
