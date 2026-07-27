<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('business_profiles', function (Blueprint $table) {
            // AI Confidence threshold (0-100)
            $table->integer('ai_confidence_threshold')->default(70)->after('ai_instructions');
            
            // AI Tone style (structured instead of free text)
            $table->json('ai_tone_style')->nullable()->after('ai_confidence_threshold');
            
            // Business hours routing
            $table->boolean('business_hours_enabled')->default(false)->after('ai_tone_style');
            $table->text('after_hours_message')->nullable()->after('business_hours_enabled');
            
            // AI Provider settings
            $table->string('ai_provider')->default('gemini')->after('after_hours_message');
            $table->string('ai_model')->nullable()->after('ai_provider');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('business_profiles', function (Blueprint $table) {
            $table->dropColumn(['ai_confidence_threshold', 'ai_tone_style', 'business_hours_enabled', 'after_hours_message']);
        });
    }
};