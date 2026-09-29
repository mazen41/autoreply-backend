<?php

namespace App\Console\Commands;

use App\Models\Channel;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ResyncTelegramWebhooks extends Command
{
    /**
     * Re-registers each connected Telegram bot's webhook against the new,
     * channel-specific URL (/api/telegram/webhook/{userId}/{channelId})
     * instead of the old ambiguous one (/api/telegram/webhook/{userId}).
     *
     * Safe to run repeatedly: setWebhook is idempotent on Telegram's side,
     * and this command only reads/POSTs using each channel's own stored
     * token — it never deletes or overwrites any Channel row.
     */
    protected $signature = 'telegram:resync-webhooks {--dry-run : List what would change without calling Telegram}';

    protected $description = 'Re-register Telegram webhooks with exact channel-id URLs (fixes multi-bot ambiguity)';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $channels = Channel::where('type', 'telegram')
            ->where('status', 'connected')
            ->get();

        $this->info("Found {$channels->count()} connected Telegram channel(s).");

        $updated = 0;
        $failed = 0;

        foreach ($channels as $channel) {
            $newUrl = env('APP_URL') . "/api/telegram/webhook/{$channel->user_id}/{$channel->id}";

            $this->line("Channel #{$channel->id} (user {$channel->user_id}, bot @{$channel->page_id}) -> {$newUrl}");

            if ($dryRun) {
                continue;
            }

            try {
                $botToken = $channel->access_token; // decrypted by accessor
                if (empty($botToken)) {
                    $this->warn("  Skipped: no access token stored for channel #{$channel->id}");
                    $failed++;
                    continue;
                }

                $response = Http::timeout(10)->post("https://api.telegram.org/bot{$botToken}/setWebhook", [
                    'url' => $newUrl,
                ]);

                if ($response->successful()) {
                    $updated++;
                } else {
                    $failed++;
                    Log::error('telegram:resync-webhooks failed for channel', [
                        'channel_id' => $channel->id,
                        'response' => $response->json(),
                    ]);
                    $this->error("  Failed: " . json_encode($response->json()));
                }
            } catch (\Exception $e) {
                $failed++;
                Log::error('telegram:resync-webhooks exception', [
                    'channel_id' => $channel->id,
                    'error' => $e->getMessage(),
                ]);
                $this->error("  Exception: {$e->getMessage()}");
            }
        }

        if ($dryRun) {
            $this->info('Dry run — no Telegram API calls made.');
        } else {
            $this->info("Done. Updated: {$updated}, Failed: {$failed}.");
        }

        return self::SUCCESS;
    }
}
