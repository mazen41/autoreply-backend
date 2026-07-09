<?php

namespace App\Console\Commands;

use App\Models\Channel;
use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class TestAutoreply extends Command
{
    protected $signature = 'test:autoreply {channelId : The ID of the channel to test}';
    protected $description = 'Test the AI auto-reply system by creating a fake inbound message and dispatching ProcessAutoReply';

    public function handle(): int
    {
        $channelId = (int) $this->argument('channelId');

        $this->info("Testing auto-reply for channel ID: {$channelId}");

        // Find the channel
        $channel = Channel::find($channelId);
        if (!$channel) {
            $this->error("Channel not found with ID: {$channelId}");
            return self::FAILURE;
        }

        $this->info("Channel found: {$channel->type} - {$channel->page_name}");
        $this->info("AI enabled: " . ($channel->ai_enabled ? 'Yes' : 'No'));

        if (!$channel->ai_enabled) {
            $this->warn("AI is not enabled for this channel. Enabling it for test...");
            $channel->update(['ai_enabled' => true]);
        }

        // Create a fake conversation
        $conversation = Conversation::create([
            'channel_id' => $channel->id,
            'business_id' => $channel->business_id,
            'sender_id' => 'test_sender_123',
            'sender_name' => 'Test Customer',
            'sender_email' => $channel->type === 'gmail' ? 'test@example.com' : null,
            'subject' => $channel->type === 'gmail' ? 'Test Subject' : null,
            'status' => 'open',
            'last_message_at' => now(),
        ]);

        $this->info("Conversation created with ID: {$conversation->id}");

        // Create a fake inbound message
        $inboundMessage = Message::create([
            'conversation_id' => $conversation->id,
            'content' => 'Hello, I have a question about your services.',
            'direction' => 'inbound',
            'is_ai' => false,
            'status' => 'received',
            'send_status' => 'delivered',
        ]);

        $this->info("Inbound message created with ID: {$inboundMessage->id}");
        $this->info("Message content: {$inboundMessage->content}");

        // Dispatch ProcessAutoReply job
        $this->info("Dispatching ProcessAutoReply job...");
        \App\Jobs\ProcessAutoReply::dispatch($inboundMessage->id);

        $this->info("Job dispatched. Waiting 10 seconds for processing...");

        // Wait for the job to process
        sleep(10);

        // Query messages table to get both inbound and outbound
        $messages = Message::where('conversation_id', $conversation->id)
            ->orderBy('created_at', 'asc')
            ->get();

        $this->newLine();
        $this->info("=== Messages in conversation ===");
        $this->table(
            ['ID', 'Direction', 'Content', 'Is AI', 'Status', 'Send Status'],
            $messages->map(fn($m) => [
                $m->id,
                $m->direction,
                substr($m->content, 0, 50) . (strlen($m->content) > 50 ? '...' : ''),
                $m->is_ai ? 'Yes' : 'No',
                $m->status,
                $m->send_status ?? 'N/A',
            ])->toArray()
        );

        // Check if AI reply was created
        $aiReply = $messages->where('direction', 'outbound')->where('is_ai', true)->first();

        if ($aiReply) {
            $this->newLine();
            $this->info("✓ AI reply created successfully!");
            $this->info("Reply content: {$aiReply->content}");
            $this->info("Send status: {$aiReply->send_status}");
        } else {
            $this->newLine();
            $this->error("✗ AI reply was not created. Check logs for errors.");
        }

        // Cleanup
        $this->newLine();
        if ($this->confirm('Clean up test data?')) {
            $messages->each->delete();
            $conversation->delete();
            $this->info("Test data cleaned up.");
        }

        return $aiReply ? self::SUCCESS : self::FAILURE;
    }
}
