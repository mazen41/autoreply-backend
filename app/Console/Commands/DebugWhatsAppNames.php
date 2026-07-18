<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Conversation;
use App\Models\WhatsAppMessage;

class DebugWhatsAppNames extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'debug:whatsapp-names';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Debug WhatsApp contact names in database';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Debugging WhatsApp contact names...');

        // Check conversations
        $this->info("\n=== CONVERSATIONS ===");
        $conversations = Conversation::whereHas('channel', function ($q) {
            $q->where('type', 'whatsapp');
        })->limit(5)->get();

        foreach ($conversations as $conv) {
            $this->info("Conversation ID: {$conv->id}");
            $this->info("  Sender ID: {$conv->sender_id}");
            $this->info("  Sender Name: {$conv->sender_name}");
            $this->info("  Channel ID: {$conv->channel_id}");
            $this->info("  ---");
        }

        // Check WhatsApp messages
        $this->info("\n=== WHATSAPP MESSAGES ===");
        $messages = WhatsAppMessage::limit(5)->get();

        foreach ($messages as $msg) {
            $this->info("Message ID: {$msg->id}");
            $this->info("  From Phone: {$msg->from_phone}");
            $this->info("  From Name: {$msg->from_name}");
            $this->info("  Direction: {$msg->direction}");
            $this->info("  ---");
        }

        // Check for messages from the specific phone number
        $this->info("\n=== MESSAGES FROM 201122570062 ===");
        $specificMessages = WhatsAppMessage::where('from_phone', 'like', '%201122570062%')
            ->limit(5)
            ->get();

        foreach ($specificMessages as $msg) {
            $this->info("Message ID: {$msg->id}");
            $this->info("  From Phone: {$msg->from_phone}");
            $this->info("  From Name: {$msg->from_name}");
            $this->info("  Direction: {$msg->direction}");
            $this->info("  Body: " . substr($msg->body, 0, 50));
            $this->info("  ---");
        }

        $this->info("\nDone!");
        
        return Command::SUCCESS;
    }
}
