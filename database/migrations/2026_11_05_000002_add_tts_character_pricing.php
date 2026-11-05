<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('ai_model_prices', 'audio_output_price_per_million_chars')) {
            Schema::table('ai_model_prices', fn (Blueprint $table) => $table->decimal('audio_output_price_per_million_chars', 18, 9)->nullable()->after('audio_price_per_minute'));
        }

        if (!Schema::hasColumn('ai_model_price_history', 'audio_output_price_per_million_chars')) {
            Schema::table('ai_model_price_history', fn (Blueprint $table) => $table->decimal('audio_output_price_per_million_chars', 18, 9)->nullable()->after('audio_price_per_minute'));
        }

        $now = now();
        DB::table('ai_model_prices')->updateOrInsert(
            ['provider' => 'openai', 'model_key' => 'tts-1'],
            ['display_name' => 'OpenAI Text to Speech', 'input_price_per_million' => 0, 'output_price_per_million' => 0, 'audio_output_price_per_million_chars' => 15, 'capabilities' => json_encode(['audio_generation']), 'pricing_version' => 'openai-2026-11', 'effective_at' => $now, 'is_active' => true, 'updated_at' => $now, 'created_at' => $now],
        );
    }

    public function down(): void
    {
        // Keep historical usage pricing intact; this additive pricing field is intentionally retained.
    }
};
