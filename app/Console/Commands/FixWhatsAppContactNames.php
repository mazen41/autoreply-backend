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

        // Find conversations where sender_name looks like a phone number
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
                ->whereNotNull('from_name')
                ->where('from_name', '!=', '')
                ->where('from_name', '!=', '.')
                ->where(function ($q) {
                    $q->where('from_name', 'not like', '+%')
                      ->where('from_name', 'not like', '0%');
                })
                ->orderBy('sent_at', 'desc')
                ->first();

            if ($whatsappMessage && $whatsappMessage->from_name) {
                $oldName = $conversation->sender_name;
                $conversation->update(['sender_name' => $whatsappMessage->from_name]);
                
                $this->info("Updated conversation {$conversation->id}: '{$oldName}' -> '{$whatsappMessage->from_name}'");
                $updated++;
            }
        }

        $this->info("Successfully updated {$updated} conversations");
        $this->info('Done!');
        
        return Command::SUCCESS;
    }
}
