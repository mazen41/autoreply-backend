<?php

namespace App\Console\Commands;

use App\Models\Message;
use App\Models\Conversation;
use App\Models\BusinessProfile;
use App\Models\Channel;
use App\Services\IncomingMediaStorage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class ExpireInboundMedia extends Command
{
    protected $signature = 'media:expire-inbound {--days= : Override the configured retention period}';

    protected $description = 'Delete expired private message media according to per-business retention';

    public function handle(): int
    {
        $storage = app(IncomingMediaStorage::class);
        $diskName = $storage->diskName();
        $expired = 0;
        $override = $this->option('days');
        $fallbackDays = max(1, (int) ($override ?: config('services.evolution.incoming_media_retention_days', 30)));
        BusinessProfile::query()->select(['id', 'media_retention_days'])->orderBy('id')->chunkById(100, function ($businesses) use ($storage, $diskName, $override, $fallbackDays, &$expired): void {
            foreach ($businesses as $business) {
                $days = max(1, (int) ($override ?: $business->media_retention_days ?: $fallbackDays));
                $conversationIds = Conversation::query()->select('id')->whereIn(
                    'channel_id', Channel::query()->select('id')->where('business_id', $business->id)
                );
                Message::query()->whereIn('conversation_id', $conversationIds)
                    ->whereNotNull('media_url')
                    ->where('created_at', '<=', now()->subDays($days))
                    ->orderBy('id')->chunkById(200, function ($messages) use ($storage, $diskName, &$expired): void {
                        foreach ($messages as $message) {
                            $path = (string) $message->media_url;
                            $mediaDisk = (string) data_get($message->metadata, 'media.storage_disk', $diskName);
                            if ($storage->absolutePath($path, $mediaDisk) === null) continue;
                            Storage::disk($mediaDisk)->delete($path);
                            $metadata = $message->metadata ?? [];
                            if (isset($metadata['media']) && is_array($metadata['media'])) {
                                $metadata['media']['url'] = null;
                                $metadata['media']['storage_status'] = 'expired';
                            }
                            $message->forceFill([
                                'media_url' => null,
                                'media_processing_status' => 'expired',
                                'media_processing_reason' => 'Media expired according to retention policy.',
                                'metadata' => $metadata,
                            ])->save();
                            $expired++;
                        }
                    });
            }
        });

        $this->info("Expired {$expired} media file(s) according to business retention settings.");
        return self::SUCCESS;
    }
}
