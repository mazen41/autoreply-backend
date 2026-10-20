<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_identities', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('business_profile_id')->constrained('business_profiles')->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->foreignId('channel_id')->constrained('channels')->cascadeOnDelete();
            $table->string('platform', 32);
            $table->string('external_id', 255);
            $table->char('external_id_hash', 64);
            $table->timestamps();
            $table->unique(['business_profile_id', 'channel_id', 'platform', 'external_id_hash'], 'customer_identity_account_unique');
            $table->index(['customer_id', 'platform'], 'customer_identity_customer_platform_index');
        });

        // Preserve the actual account scope by deriving identities from the
        // linked conversations, rather than guessing it from the legacy JSON.
        DB::table('conversations as conversations')
            ->join('channels as channels', 'channels.id', '=', 'conversations.channel_id')
            ->whereNotNull('conversations.customer_id')
            ->whereNotNull('conversations.sender_id')
            ->select([
                'conversations.business_id', 'conversations.customer_id', 'conversations.channel_id',
                'conversations.sender_id', 'channels.type as platform',
            ])
            ->orderBy('conversations.id')
            ->chunk(500, function ($rows): void {
                $now = now();
                $inserts = [];
                foreach ($rows as $row) {
                    $externalId = trim((string) $row->sender_id);
                    if ($externalId === '' || strlen($externalId) > 255) continue;
                    $inserts[] = [
                        'business_profile_id' => $row->business_id,
                        'customer_id' => $row->customer_id,
                        'channel_id' => $row->channel_id,
                        'platform' => (string) $row->platform,
                        'external_id' => $externalId,
                        'external_id_hash' => hash('sha256', $externalId),
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
                if ($inserts) DB::table('customer_identities')->insertOrIgnore($inserts);
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_identities');
    }
};
