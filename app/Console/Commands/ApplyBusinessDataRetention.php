<?php

namespace App\Console\Commands;

use App\Models\BusinessProfile;
use App\Models\Channel;
use App\Models\ContactConsent;
use App\Models\Conversation;
use App\Models\DataRightsRequest;
use App\Models\Message;
use App\Services\IncomingMediaStorage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class ApplyBusinessDataRetention extends Command
{
    protected $signature = 'data:apply-retention';
    protected $description = 'Apply per-business message and consent retention and expire data export archives';

    public function handle(): int
    {
        $storage = app(IncomingMediaStorage::class);
        $deletedMessages = 0;
        $deletedMedia = 0;
        $deletedConsents = 0;

        BusinessProfile::query()->select(['id', 'message_retention_days', 'consent_retention_days'])
            ->orderBy('id')->chunkById(100, function ($businesses) use ($storage, &$deletedMessages, &$deletedMedia, &$deletedConsents): void {
                foreach ($businesses as $business) {
                    $conversationIds = Conversation::query()->select('id')->whereIn(
                        'channel_id', Channel::query()->select('id')->where('business_id', $business->id)
                    );
                    $messages = Message::query()->whereIn('conversation_id', $conversationIds)
                        ->where('created_at', '<=', now()->subDays(max(1, (int) $business->message_retention_days)))
                        ->orderBy('id');

                    $messages->chunkById(200, function ($batch) use ($storage, &$deletedMessages, &$deletedMedia): void {
                        foreach ($batch as $message) {
                            $path = (string) $message->media_url;
                            $disk = (string) data_get($message->metadata, 'media.storage_disk', $storage->diskName());
                            if ($path !== '' && $storage->absolutePath($path, $disk) !== null && Storage::disk($disk)->delete($path)) {
                                $deletedMedia++;
                            }
                            $message->delete();
                            $deletedMessages++;
                        }
                    });

                    if ($business->consent_retention_days !== null) {
                        $consentCutoff = now()->subDays(max(1, (int) $business->consent_retention_days));
                        $deletedConsents += ContactConsent::query()->where('business_id', $business->id)
                            ->whereRaw('COALESCE(captured_at, created_at) <= ?', [$consentCutoff])->delete();
                    }
                }
            });

        $expiredArchives = 0;
        DataRightsRequest::query()->where('type', 'export')->whereNotNull('archive_path')
            ->where('archive_expires_at', '<=', now())->orderBy('id')->chunkById(200, function ($requests) use (&$expiredArchives): void {
                foreach ($requests as $request) {
                    Storage::disk('local')->delete($request->archive_path);
                    $request->update(['archive_path' => null]);
                    $expiredArchives++;
                }
            });

        $this->info("Retention applied: {$deletedMessages} messages, {$deletedMedia} media files, {$deletedConsents} consent records, {$expiredArchives} expired exports.");
        return self::SUCCESS;
    }
}
