<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('channels', function (Blueprint $table): void {
            if (!Schema::hasColumn('channels', 'last_event_at')) $table->timestamp('last_event_at')->nullable();
            if (!Schema::hasColumn('channels', 'last_sync_at')) $table->timestamp('last_sync_at')->nullable();
            if (!Schema::hasColumn('channels', 'last_error_at')) $table->timestamp('last_error_at')->nullable();
            if (!Schema::hasColumn('channels', 'last_error_code')) $table->string('last_error_code', 100)->nullable();
        });

        Schema::table('messages', function (Blueprint $table): void {
            if (!Schema::hasColumn('messages', 'channel_id')) {
                $table->foreignId('channel_id')->nullable()->after('conversation_id')->constrained('channels')->nullOnDelete();
            }
            if (!Schema::hasColumn('messages', 'platform_message_id')) {
                $table->string('platform_message_id', 512)->nullable()->after('channel_id');
            }
            if (!Schema::hasColumn('messages', 'platform_message_hash')) {
                $table->char('platform_message_hash', 64)->nullable()->after('platform_message_id');
            }
            if (!Schema::hasColumn('messages', 'correlation_id')) {
                $table->uuid('correlation_id')->nullable()->after('platform_message_hash');
            }
        });

        // The Gmail provider ID is only unique within a connected account. The
        // account-scoped composite key below is the durable uniqueness rule.
        if (Schema::hasColumn('messages', 'gmail_message_id') && Schema::hasIndex('messages', 'messages_gmail_message_id_unique')) {
            Schema::table('messages', function (Blueprint $table): void {
                $table->dropUnique('messages_gmail_message_id_unique');
            });
        }

        // Backfill channel identity from the owning conversation. Preserve the
        // first copy of historical duplicate provider IDs so this migration is
        // safe on databases that already contain duplicate webhook deliveries.
        DB::table('messages')
            ->leftJoin('conversations', 'conversations.id', '=', 'messages.conversation_id')
            ->whereNull('messages.channel_id')
            ->whereNotNull('conversations.channel_id')
            ->select('messages.id', 'messages.gmail_message_id', 'messages.whatsapp_message_id', 'messages.metadata', 'conversations.channel_id')
            ->orderBy('messages.id')
            ->chunk(500, function ($rows): void {
                foreach ($rows as $row) {
                    $providerId = $row->gmail_message_id ?: $row->whatsapp_message_id;
                    $metadata = json_decode((string) $row->metadata, true);
                    $providerId = $providerId ?: (is_array($metadata) ? ($metadata['platform_message_id'] ?? null) : null);
                    $updates = ['channel_id' => $row->channel_id];
                    if (is_string($providerId) && $providerId !== '') {
                        $hash = hash('sha256', $providerId);
                        $alreadyUsed = DB::table('messages')
                            ->where('channel_id', $row->channel_id)
                            ->where('platform_message_hash', $hash)
                            ->exists();
                        if (!$alreadyUsed) {
                            $updates['platform_message_id'] = $providerId;
                            $updates['platform_message_hash'] = $hash;
                        }
                    }
                    DB::table('messages')->where('id', $row->id)->update($updates);
                }
            });

        if (!Schema::hasIndex('messages', 'messages_channel_platform_hash_unique')) {
            Schema::table('messages', function (Blueprint $table): void {
                $table->unique(['channel_id', 'platform_message_hash'], 'messages_channel_platform_hash_unique');
            });
        }
        if (!Schema::hasIndex('messages', 'messages_correlation_id_index')) {
            Schema::table('messages', function (Blueprint $table): void {
                $table->index('correlation_id', 'messages_correlation_id_index');
            });
        }
    }

    public function down(): void
    {
        foreach (['last_event_at', 'last_sync_at', 'last_error_at', 'last_error_code'] as $column) {
            if (Schema::hasColumn('channels', $column)) {
                Schema::table('channels', fn (Blueprint $table) => $table->dropColumn($column));
            }
        }
        if (Schema::hasIndex('messages', 'messages_channel_platform_hash_unique')) {
            Schema::table('messages', fn (Blueprint $table) => $table->dropUnique('messages_channel_platform_hash_unique'));
        }
        if (Schema::hasIndex('messages', 'messages_correlation_id_index')) {
            Schema::table('messages', fn (Blueprint $table) => $table->dropIndex('messages_correlation_id_index'));
        }
        if (Schema::hasColumn('messages', 'correlation_id')) {
            Schema::table('messages', fn (Blueprint $table) => $table->dropColumn('correlation_id'));
        }
        if (Schema::hasColumn('messages', 'platform_message_hash')) {
            Schema::table('messages', fn (Blueprint $table) => $table->dropColumn('platform_message_hash'));
        }
        if (Schema::hasColumn('messages', 'platform_message_id')) {
            Schema::table('messages', fn (Blueprint $table) => $table->dropColumn('platform_message_id'));
        }
        if (Schema::hasColumn('messages', 'channel_id')) {
            Schema::table('messages', function (Blueprint $table): void {
                $table->dropForeign(['channel_id']);
                $table->dropColumn('channel_id');
            });
        }
        if (Schema::hasColumn('messages', 'gmail_message_id') && !Schema::hasIndex('messages', 'messages_gmail_message_id_unique')) {
            Schema::table('messages', fn (Blueprint $table) => $table->unique('gmail_message_id'));
        }
    }
};
