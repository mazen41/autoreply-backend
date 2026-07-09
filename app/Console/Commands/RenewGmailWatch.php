<?php

namespace App\Console\Commands;

use App\Models\Channel;
use App\Http\Controllers\GmailController;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class RenewGmailWatch extends Command
{
    protected $signature = 'gmail:renew-watch';
    protected $description = 'Renew Gmail watch for all connected Gmail channels (expires every 7 days)';

    public function handle(): int
    {
        $this->info('Renewing Gmail watch for all connected channels...');

        $channels = Channel::where('type', 'gmail')
            ->where('status', 'connected')
            ->get();

        if ($channels->isEmpty()) {
            $this->info('No connected Gmail channels found.');
            return self::SUCCESS;
        }

        $renewed = 0;
        $failed = 0;

        foreach ($channels as $channel) {
            $this->info("Processing channel: {$channel->page_name}");

            // Check if watch expires in the next 2 days (renew early)
            if ($channel->gmail_watch_expires_at && $channel->gmail_watch_expires_at->gt(now()->addDays(2))) {
                $this->line("  - Watch expires at {$channel->gmail_watch_expires_at}, skipping (still valid)");
                continue;
            }

            try {
                $gmailCtrl = new GmailController();
                // Call the private setupGmailWatch method via reflection
                $reflection = new \ReflectionClass($gmailCtrl);
                $method = $reflection->getMethod('setupGmailWatch');
                $method->setAccessible(true);
                $method->invoke($gmailCtrl, $channel);

                $this->line("  - Watch renewed successfully");
                $renewed++;
            } catch (\Exception $e) {
                $this->error("  - Failed to renew watch: {$e->getMessage()}");
                Log::error('Gmail watch renewal failed', [
                    'channel_id' => $channel->id,
                    'error' => $e->getMessage(),
                ]);
                $failed++;
            }
        }

        $this->info("Gmail watch renewal complete: {$renewed} renewed, {$failed} failed");
        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
