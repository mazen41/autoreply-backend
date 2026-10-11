<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campaigns', function (Blueprint $table) {
            $table->foreignId('whatsapp_cloud_template_id')
                ->nullable()
                ->after('channel_id')
                ->constrained('whatsapp_cloud_templates')
                ->nullOnDelete();
            $table->json('whatsapp_template_components')->nullable()->after('message');
        });
    }

    public function down(): void
    {
        Schema::table('campaigns', function (Blueprint $table) {
            $table->dropConstrainedForeignId('whatsapp_cloud_template_id');
            $table->dropColumn('whatsapp_template_components');
        });
    }
};
