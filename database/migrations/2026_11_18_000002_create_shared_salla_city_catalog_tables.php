<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('salla_city_catalogs', function (Blueprint $table): void {
            $table->id();
            $table->string('country_code', 2)->unique();
            $table->unsignedBigInteger('country_id')->nullable()->unique();
            $table->string('status', 24)->default('pending');
            $table->uuid('generation')->nullable();
            $table->unsignedInteger('pages_completed')->default(0);
            $table->unsignedInteger('total_pages')->nullable();
            $table->unsignedInteger('expected_city_count')->nullable();
            $table->unsignedInteger('staged_city_count')->default(0);
            $table->unsignedInteger('saved_city_count')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->unsignedBigInteger('source_channel_id')->nullable();
            $table->char('id_set_sha256', 64)->nullable();
            $table->timestamp('cross_store_verified_at')->nullable();
            $table->unsignedBigInteger('verification_channel_id')->nullable();
            $table->char('verification_id_set_sha256', 64)->nullable();
            $table->boolean('verification_matches')->nullable();
            $table->string('last_error_code', 64)->nullable();
            $table->text('last_error_message')->nullable();
            $table->timestamps();
            $table->index(['status', 'updated_at'], 'salla_city_catalog_status_updated_idx');
        });

        Schema::create('salla_city_reference_cities', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('country_id');
            $table->unsignedBigInteger('salla_city_id');
            $table->string('name');
            $table->string('name_en')->nullable();
            $table->string('name_ar')->nullable();
            $table->string('normalized_name', 180)->nullable();
            $table->string('normalized_en', 180)->nullable();
            $table->string('normalized_ar', 180)->nullable();
            $table->timestamp('synced_at');
            $table->timestamps();
            $table->unique(['country_id', 'salla_city_id'], 'salla_city_reference_country_city_uq');
            $table->index(['country_id', 'normalized_name'], 'salla_city_reference_country_name_idx');
            $table->index(['country_id', 'normalized_en'], 'salla_city_reference_country_en_idx');
            $table->index(['country_id', 'normalized_ar'], 'salla_city_reference_country_ar_idx');
        });

        Schema::create('salla_city_reference_staging', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('country_id');
            $table->uuid('generation');
            $table->unsignedBigInteger('salla_city_id');
            $table->string('name');
            $table->string('name_en')->nullable();
            $table->string('name_ar')->nullable();
            $table->string('normalized_name', 180)->nullable();
            $table->string('normalized_en', 180)->nullable();
            $table->string('normalized_ar', 180)->nullable();
            $table->timestamps();
            $table->unique(['country_id', 'generation', 'salla_city_id'], 'salla_city_stage_country_generation_city_uq');
            $table->index(['country_id', 'generation'], 'salla_city_stage_country_generation_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('salla_city_reference_staging');
        Schema::dropIfExists('salla_city_reference_cities');
        Schema::dropIfExists('salla_city_catalogs');
    }
};
