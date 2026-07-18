<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\WhatsAppMessage;

class CheckWhatsAppMetadata extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'check:whatsapp-metadata';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Check WhatsApp message metadata for pushName';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Checking WhatsApp message metadata...');

        // Check recent messages
        $messages = WhatsAppMessage::whereNotNull('metadata')
            ->orderBy('sent_at', 'desc')
            ->limit(10)
            ->get();

        foreach ($messages as $msg) {
            $this->info("\n=== Message ID: {$msg->id} ===");
            $this->info("From Phone: {$msg->from_phone}");
            $this->info("From Name: {$msg->from_name}");
            
            $metadata = $msg->metadata;
            if (is_array($metadata)) {
                $this->info("Metadata Keys: " . implode(', ', array_keys($metadata)));
                
                if (isset($metadata['pushName'])) {
                    $this->info("pushName in metadata: {$metadata['pushName']}");
                }
                
                if (isset($metadata['event'])) {
                    $event = $metadata['event'];
                    if (isset($event['data']['key']['pushName'])) {
                        $this->info("pushName in event data: {$event['data']['key']['pushName']}");
                    }
                }
            }
        }

        $this->info("\nDone!");
        
        return Command::SUCCESS;
    }
}
