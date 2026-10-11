<?php

namespace App\Jobs;

use App\Models\CommerceOrder;
use App\Models\Channel;
use App\Models\ContactConsent;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\CustomerNote;
use App\Models\DataRightsRequest;
use App\Models\Message;
use App\Models\Suppression;
use App\Models\WhatsAppInstance;
use App\Models\WhatsAppMessage;
use App\Services\IncomingMediaStorage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use ZipArchive;

class ExportBusinessCustomerData implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public int $requestId)
    {
    }

    public function handle(): void
    {
        $request = DataRightsRequest::query()->where('type', 'export')->findOrFail($this->requestId);
        $request->update(['status' => 'processing', 'failure_reason' => null]);
        $businessId = (int) $request->business_id;
        $conversationQuery = static fn () => Conversation::query()->whereIn(
            'channel_id', Channel::query()->select('id')->where('business_id', $businessId)
        );
        $disk = Storage::disk('local');
        $workDir = storage_path('app/private/data-exports/.work-' . $request->id);

        $specs = [
            'customers.jsonl' => [Customer::query()->where('business_profile_id', $businessId), ['id','business_profile_id','commerce_channel_id','commerce_external_id','name','phone','email','avatar','lead_score','tags','custom_fields','created_at','updated_at']],
            'conversations.jsonl' => [$conversationQuery(), ['id','channel_id','business_id','bot_id','customer_id','sender_id','sender_name','sender_email','subject','status','checkout_state','created_at','updated_at']],
            'messages.jsonl' => [Message::query()->whereIn('conversation_id', $conversationQuery()->select('id')), ['id','conversation_id','content','content_html','type','direction','status','is_ai','source','media_url','media_type','mime_type','file_name','metadata','created_at','updated_at']],
            'customer_notes.jsonl' => [CustomerNote::query()->whereIn('customer_id', Customer::query()->select('id')->where('business_profile_id', $businessId)), ['id','customer_id','user_id','conversation_id','content','created_at','updated_at']],
            'consents.jsonl' => [ContactConsent::query()->where('business_id', $businessId), ['id','business_id','channel','contact_identifier','scope','status','source','captured_at','evidence','created_at','updated_at']],
            'suppressions.jsonl' => [Suppression::query()->where('business_id', $businessId), ['id','business_id','channel','contact_identifier','reason','source','suppressed_at','created_at','updated_at']],
            'commerce_orders.jsonl' => [CommerceOrder::query()->where('business_id', $businessId), ['id','business_id','channel_id','external_id','order_number','status','fulfillment_status','total','currency','customer_name','customer_email','customer_phone','shipping_address','line_items','raw_data','ordered_at','created_at','updated_at']],
            'whatsapp_messages.jsonl' => [WhatsAppMessage::query()->whereIn('whatsapp_instance_id', WhatsAppInstance::query()->select('id')->where('business_id', $businessId)), ['id','whatsapp_instance_id','user_id','message_id','remote_message_id','direction','from_phone','from_name','to_phone','body','message_type','media','metadata','status','sent_at','created_at','updated_at']],
        ];

        try {
            if (!is_dir($workDir) && !mkdir($workDir, 0700, true) && !is_dir($workDir)) {
                throw new \RuntimeException('Could not prepare export workspace.');
            }
            foreach ($specs as $filename => [$query, $columns]) {
                $path = $workDir . DIRECTORY_SEPARATOR . $filename;
                $handle = fopen($path, 'wb');
                $query->select($columns)->orderBy('id')->chunkById(500, function ($rows) use ($handle): void {
                    foreach ($rows as $row) {
                        fwrite($handle, json_encode($row->toArray(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
                    }
                });
                fclose($handle);
            }

            $relativePath = 'data-exports/' . $businessId . '/customer-data-' . $request->id . '.zip';
            $disk->makeDirectory(dirname($relativePath));
            $absoluteZip = $disk->path($relativePath);
            $zip = new ZipArchive();
            if ($zip->open($absoluteZip, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new \RuntimeException('Could not create data export archive.');
            }
            foreach (array_keys($specs) as $filename) {
                if (!$zip->addFile($workDir . DIRECTORY_SEPARATOR . $filename, $filename)) {
                    throw new \RuntimeException('Could not add an export data file to the archive.');
                }
            }
            $mediaManifest = fopen($workDir . DIRECTORY_SEPARATOR . 'media_manifest.jsonl', 'wb');
            $mediaStorage = app(IncomingMediaStorage::class);
            Message::query()->whereIn('conversation_id', $conversationQuery()->select('id'))
                ->whereNotNull('media_url')->orderBy('id')->chunkById(200, function ($messages) use ($mediaStorage, $zip, $mediaManifest): void {
                    foreach ($messages as $message) {
                        $diskName = (string) data_get($message->metadata, 'media.storage_disk', $mediaStorage->diskName());
                        $path = (string) $message->media_url;
                        $absolutePath = $mediaStorage->absolutePath($path, $diskName);
                        if ($absolutePath === null) continue;
                        $member = 'media/' . $message->id . '-' . basename($absolutePath);
                        if ($zip->addFile($absolutePath, $member)) {
                            fwrite($mediaManifest, json_encode(['message_id' => $message->id, 'archive_path' => $member], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
                        }
                    }
                });
            fclose($mediaManifest);
            $zip->addFile($workDir . DIRECTORY_SEPARATOR . 'media_manifest.jsonl', 'media_manifest.jsonl');
            $zip->addFromString('README.txt', "Customer data export for business {$businessId}. Generated " . now()->toIso8601String() . ".\nPersonal data is included; store and share this archive securely.\n");
            if (!$zip->close()) {
                throw new \RuntimeException('Could not finalize the data export archive.');
            }

            $request->update([
                'status' => 'completed',
                'archive_path' => $relativePath,
                'archive_expires_at' => now()->addDays(7),
                'completed_at' => now(),
                'receipt' => ['archive_format' => 'zip', 'files' => count($specs), 'expires_at' => now()->addDays(7)->toIso8601String()],
            ]);
        } catch (\Throwable $exception) {
            $request->update(['status' => 'failed', 'failure_reason' => 'Export generation failed.', 'completed_at' => now()]);
            throw $exception;
        } finally {
            foreach (glob($workDir . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($workDir);
        }
    }
}
