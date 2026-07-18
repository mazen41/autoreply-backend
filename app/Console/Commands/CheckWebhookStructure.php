<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\WhatsAppMessage;

class CheckWebhookStructure extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'check:webhook-structure';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Check webhook structure to find pushName location';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Checking webhook structure...');

        // Check the most recent message from 201122570062
        $message = WhatsAppMessage::where('from_phone', 'like', '%201122570062%')
            ->orderBy('sent_at', 'desc')
            ->first();

        if (!$message) {
            $this->error('No message found from 201122570062');
            return Command::FAILURE;
        }

        $this->info("\n=== Message ID: {$message->id} ===");
        $this->info("From Phone: {$message->from_phone}");
        $this->info("From Name: {$message->from_name}");

        $metadata = $message->metadata;
        if (is_array($metadata)) {
            $this->info("\n=== FULL METADATA ===");
            $this->info(json_encode($metadata, JSON_PRETTY_PRINT));
            
            if (isset($metadata['event'])) {
                $event = $metadata['event'];
                $this->info("\n=== EVENT STRUCTURE ===");
                $this->info(json_encode($event, JSON_PRETTY_PRINT));
                
                // Check for pushName in different locations
                $this->info("\n=== SEARCHING FOR pushName ===");
                
                if (isset($event['data']['key']['pushName'])) {
                    $this->info("✓ Found in event.data.key.pushName: {$event['data']['key']['pushName']}");
                } else {
                    $this->info("✗ Not found in event.data.key.pushName");
                }
                
                if (isset($event['data']['message']['pushName'])) {
                    $this->info("✓ Found in event.data.message.pushName: {$event['data']['message']['pushName']}");
                } else {
                    $this->info("✗ Not found in event.data.message.pushName");
                }
                
                if (isset($event['pushName'])) {
                    $this->info("✓ Found in event.pushName: {$event['pushName']}");
                } else {
                    $this->info("✗ Not found in event.pushName");
                }
            }
        }

        $this->info("\nDone!");
        
        return Command::SUCCESS;
    }
}
