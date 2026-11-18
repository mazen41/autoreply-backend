<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('salla_city_catalog_syncs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('channel_id')->constrained()->cascadeOnDelete();
            $table->string('country_code', 2);
            $table->string('status', 24)->default('pending');
            $table->unsignedInteger('pages_completed')->default(0);
            $table->unsignedInteger('attempt_expected_city_count')->nullable();
            $table->unsignedInteger('expected_city_count')->nullable();
            $table->unsignedInteger('saved_city_count')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('last_successful_sync_at')->nullable();
            $table->string('last_error_code', 64)->nullable();
            $table->text('last_error_message')->nullable();
            $table->timestamps();
            $table->unique(['channel_id', 'country_code'], 'salla_city_sync_channel_country_unique');
            $table->index(['status', 'updated_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('salla_city_catalog_syncs');
    }
};
