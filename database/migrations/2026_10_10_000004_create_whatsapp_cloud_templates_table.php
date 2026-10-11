<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_cloud_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained('business_profiles')->cascadeOnDelete();
            $table->foreignId('channel_id')->constrained('channels')->cascadeOnDelete();
            $table->string('provider_template_id')->nullable();
            $table->string('name', 512);
            $table->string('language', 20);
            $table->string('category', 40);
            $table->json('components');
            $table->string('status', 40)->default('PENDING');
            $table->string('rejection_reason')->nullable();
            $table->timestamps();

            $table->unique(['channel_id', 'name', 'language'], 'wa_cloud_templates_channel_name_language_unique');
            $table->index(['business_id', 'status'], 'wa_cloud_templates_business_status_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_cloud_templates');
    }
};
