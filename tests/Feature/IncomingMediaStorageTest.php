<?php

namespace Tests\Feature;

use App\Services\IncomingMediaStorage;
use App\Services\EvolutionApiService;
use App\Models\Message;
use Illuminate\Support\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class IncomingMediaStorageTest extends TestCase
{
    use RefreshDatabase;

    public function test_inbound_media_is_stored_privately_and_resolver_rejects_traversal(): void
    {
        Storage::fake('private_media');
        config(['services.evolution.incoming_media_disk' => 'private_media']);
        $storage = app(IncomingMediaStorage::class);

        $path = $storage->store('test binary', 'jpg');

        $this->assertStringStartsWith('media/inbound/', $path);
        Storage::disk('private_media')->assertExists($path);
        $this->assertNotNull($storage->absolutePath($path));
        $this->assertNull($storage->absolutePath('../../.env'));
        $this->assertNull($storage->absolutePath('https://attacker.example/file.jpg'));
    }

    public function test_evolution_media_ingestion_uses_the_private_media_disk(): void
    {
        Storage::fake('private_media');
        config(['services.evolution.incoming_media_disk' => 'private_media']);

        $service = new class extends EvolutionApiService {
            public function ingest(array $content): ?array
            {
                return $this->extractAndStoreMedia(
                    $content,
                    [],
                    'instance-test',
                    ['id' => 'provider-message-1']
                );
            }
        };

        $media = $service->ingest([
            'imageMessage' => [
                'base64' => base64_encode('private image bytes'),
                'mimetype' => 'image/jpeg',
            ],
        ]);

        $this->assertSame('private_media', $media['storage_disk']);
        $this->assertSame('stored', $media['storage_status']);
        Storage::disk('private_media')->assertExists($media['url']);
    }

    public function test_retention_command_expires_only_private_inbound_media_older_than_policy(): void
    {
        Storage::fake('private_media');
        config(['services.evolution.incoming_media_disk' => 'private_media']);
        $storage = app(IncomingMediaStorage::class);
        $oldPath = $storage->store('old media', 'jpg');
        $recentPath = $storage->store('recent media', 'jpg');

        $old = Message::factory()->create([
            'direction' => 'inbound',
            'media_url' => $oldPath,
            'media_type' => 'image',
            'media_processing_status' => 'processed',
            'metadata' => ['media' => ['url' => $oldPath, 'storage_disk' => 'private_media', 'storage_status' => 'stored']],
            'created_at' => Carbon::now()->subDays(45),
        ]);
        $recent = Message::factory()->create([
            'direction' => 'inbound',
            'media_url' => $recentPath,
            'media_type' => 'image',
            'metadata' => ['media' => ['url' => $recentPath, 'storage_disk' => 'private_media', 'storage_status' => 'stored']],
            'created_at' => Carbon::now()->subDays(2),
        ]);

        $this->artisan('media:expire-inbound', ['--days' => 30])->assertExitCode(0);

        Storage::disk('private_media')->assertMissing($oldPath);
        Storage::disk('private_media')->assertExists($recentPath);
        $this->assertNull($old->fresh()->media_url);
        $this->assertSame('expired', $old->fresh()->media_processing_status);
        $this->assertSame('expired', $old->fresh()->metadata['media']['storage_status']);
        $this->assertSame($recentPath, $recent->fresh()->media_url);
    }
}
