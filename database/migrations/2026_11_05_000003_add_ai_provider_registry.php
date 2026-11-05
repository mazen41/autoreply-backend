<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_providers', function (Blueprint $table): void {
            $table->id();
            $table->string('slug', 40)->unique();
            $table->string('display_name', 100);
            $table->string('adapter', 40);
            $table->text('credentials')->nullable();
            $table->json('config')->nullable();
            $table->boolean('is_active')->default(false);
            $table->unsignedInteger('priority')->default(100);
            $table->unsignedSmallInteger('timeout_seconds')->default(30);
            $table->timestamps();
        });

        $now = now();
        foreach ([
            ['openai', 'OpenAI', 'openai', 'services.openai.api_key'],
            ['claude', 'Anthropic', 'anthropic', 'services.claude.api_key'],
            ['gemini', 'Google Gemini', 'gemini', 'services.gemini.api_key'],
            ['groq', 'Groq', 'openai-compatible', 'services.groq.api_key'],
        ] as [$slug, $name, $adapter, $configKey]) {
            DB::table('ai_providers')->insertOrIgnore([
                'slug' => $slug,
                'display_name' => $name,
                'adapter' => $adapter,
                'is_active' => filled(config($configKey)),
                'priority' => match ($slug) { 'groq' => 10, 'gemini' => 20, 'openai' => 30, default => 40 },
                'timeout_seconds' => (int) config('services.ai.timeout', 30),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_providers');
    }
};
