<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Segments table
        Schema::create('segments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('business_profile_id');
            $table->string('name');
            $table->json('rules')->nullable();
            $table->timestamps();

            $table->foreign('business_profile_id')->references('id')->on('business_profiles')->onDelete('cascade');
            $table->index(['business_profile_id', 'created_at'], 'segments_business_created_index');
        });

        // Campaign templates table
        Schema::create('campaign_templates', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('business_profile_id');
            $table->string('title');
            $table->string('channel_type')->default('whatsapp');
            $table->text('body');
            $table->json('variables')->nullable();
            $table->string('category')->default('general');
            $table->timestamps();

            $table->foreign('business_profile_id')->references('id')->on('business_profiles')->onDelete('cascade');
            $table->index(['business_profile_id', 'category'], 'campaign_templates_business_category_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaign_templates');
        Schema::dropIfExists('segments');
    }
};
