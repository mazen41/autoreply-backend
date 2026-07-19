<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->string('media_url')->nullable()->after('reactions');
            $table->string('media_type')->nullable()->after('media_url');
            $table->string('mime_type')->nullable()->after('media_type');
            $table->string('file_name')->nullable()->after('mime_type');
            $table->unsignedBigInteger('file_size')->nullable()->after('file_name');
            $table->unsignedInteger('duration')->nullable()->after('file_size');
            $table->string('whatsapp_message_id')->nullable()->index()->after('duration');
            $table->string('whatsapp_remote_jid')->nullable()->after('whatsapp_message_id');
            $table->boolean('whatsapp_from_me')->nullable()->after('whatsapp_remote_jid');
            $table->json('metadata')->nullable()->after('whatsapp_from_me');
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropColumn([
                'media_url',
                'media_type',
                'mime_type',
                'file_name',
                'file_size',
                'duration',
                'whatsapp_message_id',
                'whatsapp_remote_jid',
                'whatsapp_from_me',
                'metadata',
            ]);
        });
    }
};
