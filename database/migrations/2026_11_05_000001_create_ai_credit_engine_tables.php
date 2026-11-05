<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('packages', 'ai_credits_monthly')) {
            Schema::table('packages', function (Blueprint $table): void {
                $table->unsignedBigInteger('ai_credits_monthly')->default(0);
            });
        }

        Schema::create('ai_credit_wallets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('business_id')->unique()->constrained('business_profiles')->cascadeOnDelete();
            $table->unsignedBigInteger('subscription_balance')->default(0);
            $table->unsignedBigInteger('purchased_balance')->default(0);
            $table->unsignedBigInteger('reserved_balance')->default(0);
            $table->unsignedBigInteger('total_consumed')->default(0);
            $table->string('billing_period_key', 80)->nullable();
            $table->timestamp('billing_period_starts_at')->nullable();
            $table->timestamp('billing_period_ends_at')->nullable();
            $table->json('notified_thresholds')->nullable();
            $table->timestamps();
        });

        Schema::create('ai_credit_transactions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('wallet_id')->constrained('ai_credit_wallets')->cascadeOnDelete();
            $table->foreignId('business_id')->constrained('business_profiles')->cascadeOnDelete();
            $table->uuid('operation_id')->nullable();
            $table->string('type', 24);
            $table->string('source', 32)->default('subscription');
            $table->unsignedBigInteger('amount');
            $table->unsignedBigInteger('balance_before');
            $table->unsignedBigInteger('balance_after');
            $table->text('description')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['business_id', 'created_at'], 'ai_credit_tx_business_created_idx');
            $table->index(['operation_id', 'type'], 'ai_credit_tx_operation_type_idx');
        });

        Schema::create('ai_model_prices', function (Blueprint $table): void {
            $table->id();
            $table->string('provider', 40);
            $table->string('model_key', 180);
            $table->string('display_name', 180);
            $table->decimal('input_price_per_million', 18, 9)->default(0);
            $table->decimal('output_price_per_million', 18, 9)->default(0);
            $table->decimal('cached_input_price_per_million', 18, 9)->nullable();
            $table->decimal('image_price_per_unit', 18, 9)->nullable();
            $table->decimal('audio_price_per_minute', 18, 9)->nullable();
            $table->json('capabilities')->nullable();
            $table->unsignedBigInteger('max_context_tokens')->nullable();
            $table->unsignedBigInteger('max_output_tokens')->nullable();
            $table->string('pricing_version', 80)->default('initial-2026-11');
            $table->timestamp('effective_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['provider', 'model_key'], 'ai_model_prices_provider_model_unique');
        });

        Schema::create('ai_routing_rules', function (Blueprint $table): void {
            $table->id();
            $table->string('task_type', 80);
            $table->string('operating_mode', 20);
            $table->foreignId('primary_model_id')->constrained('ai_model_prices');
            $table->json('fallback_model_ids')->nullable();
            $table->unsignedTinyInteger('max_retries')->default(1);
            $table->unsignedInteger('max_latency_ms')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['task_type', 'operating_mode'], 'ai_routing_task_mode_unique');
        });

        Schema::create('ai_credit_settings', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('credits_per_usd')->default(10000);
            $table->decimal('cost_multiplier', 8, 4)->default(2);
            $table->unsignedBigInteger('reservation_minimum')->default(10);
            $table->unsignedBigInteger('reservation_maximum')->default(5000);
            $table->json('notification_thresholds')->nullable();
            $table->json('enabled_modes')->nullable();
            $table->boolean('system_enabled')->default(true);
            $table->timestamps();
        });

        Schema::create('ai_credit_packages', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 80)->unique();
            $table->unsignedBigInteger('credits');
            $table->decimal('price_sar', 10, 2);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('ai_credit_purchases', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('business_id')->constrained('business_profiles')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('credit_package_id')->constrained('ai_credit_packages');
            $table->string('status', 24)->default('pending');
            $table->unsignedBigInteger('credits');
            $table->unsignedInteger('expected_amount_minor');
            $table->string('currency', 3)->default('SAR');
            $table->string('payment_gateway', 32)->default('myfatoorah');
            $table->string('invoice_id')->nullable()->unique();
            $table->string('payment_id')->nullable();
            $table->json('gateway_response')->nullable();
            $table->timestamp('fulfilled_at')->nullable();
            $table->timestamps();
            $table->index(['business_id', 'created_at'], 'ai_credit_purchases_business_created_idx');
        });

        Schema::create('ai_usage_logs', function (Blueprint $table): void {
            $table->id();
            $table->uuid('operation_id')->unique();
            $table->foreignId('business_id')->constrained('business_profiles')->cascadeOnDelete();
            $table->unsignedBigInteger('conversation_id')->nullable()->index();
            $table->unsignedBigInteger('assistant_id')->nullable();
            $table->string('task_type', 80);
            $table->string('provider', 40)->nullable();
            $table->string('model_key', 180)->nullable();
            $table->unsignedBigInteger('input_tokens')->default(0);
            $table->unsignedBigInteger('output_tokens')->default(0);
            $table->unsignedBigInteger('cached_tokens')->default(0);
            $table->decimal('provider_cost_usd', 18, 12)->default(0);
            $table->unsignedBigInteger('credits_consumed')->default(0);
            $table->string('operating_mode', 20)->default('auto');
            $table->string('status', 24)->default('pending');
            $table->unsignedInteger('latency_ms')->nullable();
            $table->string('pricing_version', 80)->nullable();
            $table->json('metadata')->nullable();
            $table->longText('response_payload')->nullable();
            $table->timestamps();
            $table->index(['business_id', 'created_at'], 'ai_usage_business_created_idx');
            $table->index(['provider', 'model_key'], 'ai_usage_provider_model_idx');
            $table->index('status');
        });

        Schema::create('ai_business_settings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('business_id')->unique()->constrained('business_profiles')->cascadeOnDelete();
            $table->string('operating_mode', 20)->default('auto');
            $table->json('notification_thresholds')->nullable();
            $table->json('mode_preferences')->nullable();
            $table->timestamps();
        });

        Schema::create('ai_model_price_history', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('model_price_id')->index();
            $table->string('provider', 40);
            $table->string('model_key', 180);
            $table->decimal('input_price_per_million', 18, 9);
            $table->decimal('output_price_per_million', 18, 9);
            $table->decimal('cached_input_price_per_million', 18, 9)->nullable();
            $table->decimal('image_price_per_unit', 18, 9)->nullable();
            $table->decimal('audio_price_per_minute', 18, 9)->nullable();
            $table->string('pricing_version', 80);
            $table->timestamp('effective_from')->nullable();
            $table->timestamp('effective_until')->useCurrent();
            $table->timestamps();
        });

        Schema::create('ai_provider_health', function (Blueprint $table): void {
            $table->id();
            $table->string('provider', 40);
            $table->string('model_key', 180);
            $table->unsignedTinyInteger('consecutive_failures')->default(0);
            $table->unsignedSmallInteger('last_error_code')->nullable();
            $table->timestamp('last_failure_at')->nullable();
            $table->timestamp('last_success_at')->nullable();
            $table->timestamp('available_after')->nullable();
            $table->timestamps();
            $table->unique(['provider', 'model_key'], 'ai_provider_health_model_unique');
        });

        // Existing test/prod environments may contain legacy lower-case plan
        // names (for example `starter`). Match without changing those names.
        DB::table('packages')->whereRaw('LOWER(name) = ?', ['free'])->update(['ai_credits_monthly' => 1000]);
        DB::table('packages')->whereRaw('LOWER(name) = ?', ['starter'])->update(['ai_credits_monthly' => 10000]);
        DB::table('packages')->whereRaw('LOWER(name) = ?', ['growth'])->update(['ai_credits_monthly' => 30000]);
        DB::table('packages')->whereRaw('LOWER(name) = ?', ['business'])->update(['ai_credits_monthly' => 80000]);

        $now = now();
        DB::table('ai_model_prices')->insertOrIgnore([
            ['provider' => 'groq', 'model_key' => 'openai/gpt-oss-120b', 'display_name' => 'GPT OSS 120B (Groq)', 'input_price_per_million' => 0.15, 'output_price_per_million' => 0.60, 'cached_input_price_per_million' => 0.075, 'image_price_per_unit' => null, 'audio_price_per_minute' => null, 'capabilities' => json_encode(['text', 'json', 'tools', 'reasoning']), 'max_context_tokens' => 131072, 'max_output_tokens' => 65536, 'pricing_version' => 'groq-2026-11', 'effective_at' => $now, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['provider' => 'gemini', 'model_key' => 'gemini-2.5-flash', 'display_name' => 'Gemini 2.5 Flash', 'input_price_per_million' => 0.30, 'output_price_per_million' => 2.50, 'cached_input_price_per_million' => 0.03, 'image_price_per_unit' => null, 'audio_price_per_minute' => null, 'capabilities' => json_encode(['text', 'image', 'audio', 'json', 'tools']), 'max_context_tokens' => 1048576, 'max_output_tokens' => 65536, 'pricing_version' => 'google-2026-11', 'effective_at' => $now, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['provider' => 'openai', 'model_key' => 'gpt-4o', 'display_name' => 'GPT-4o Vision', 'input_price_per_million' => 2.50, 'output_price_per_million' => 10.00, 'cached_input_price_per_million' => 1.25, 'image_price_per_unit' => null, 'audio_price_per_minute' => null, 'capabilities' => json_encode(['text', 'image', 'json', 'tools']), 'max_context_tokens' => 128000, 'max_output_tokens' => 16384, 'pricing_version' => 'openai-2026-11', 'effective_at' => $now, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['provider' => 'openai', 'model_key' => 'whisper-1', 'display_name' => 'Whisper transcription', 'input_price_per_million' => 0, 'output_price_per_million' => 0, 'cached_input_price_per_million' => null, 'image_price_per_unit' => null, 'audio_price_per_minute' => 0.006, 'capabilities' => json_encode(['audio_transcription']), 'max_context_tokens' => null, 'max_output_tokens' => null, 'pricing_version' => 'openai-2026-11', 'effective_at' => $now, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['provider' => 'gemini', 'model_key' => 'gemini-embedding-001', 'display_name' => 'Gemini Embedding 001', 'input_price_per_million' => 0.15, 'output_price_per_million' => 0, 'cached_input_price_per_million' => null, 'image_price_per_unit' => null, 'audio_price_per_minute' => null, 'capabilities' => json_encode(['embedding']), 'max_context_tokens' => 2048, 'max_output_tokens' => null, 'pricing_version' => 'google-2026-11', 'effective_at' => $now, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
        ]);

        DB::table('ai_credit_settings')->insertOrIgnore([
            'id' => 1, 'credits_per_usd' => 10000, 'cost_multiplier' => 2, 'reservation_minimum' => 10,
            'reservation_maximum' => 5000, 'notification_thresholds' => json_encode([50, 80, 90, 100]),
            'enabled_modes' => json_encode(['auto', 'economy', 'premium']), 'system_enabled' => true, 'created_at' => $now, 'updated_at' => $now,
        ]);

        $groqModelId = DB::table('ai_model_prices')->where('provider', 'groq')->where('model_key', 'openai/gpt-oss-120b')->value('id');
        $geminiModelId = DB::table('ai_model_prices')->where('provider', 'gemini')->where('model_key', 'gemini-2.5-flash')->value('id');
        foreach (['customer_reply', 'ai_assistant', 'comment_reply', 'intent_detection', 'lead_qualification', 'campaign_generation', 'summarization', 'knowledge_generation'] as $task) {
            foreach (['auto' => $groqModelId, 'economy' => $groqModelId, 'premium' => $geminiModelId] as $mode => $modelId) {
                $fallbackId = $mode === 'premium' ? $groqModelId : $geminiModelId;
                if ($modelId) DB::table('ai_routing_rules')->insertOrIgnore(['task_type' => $task, 'operating_mode' => $mode, 'primary_model_id' => $modelId, 'fallback_model_ids' => json_encode($fallbackId ? [$fallbackId] : []), 'max_retries' => 1, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now]);
            }
        }

        foreach ([[5000, 25], [15000, 65], [30000, 119], [75000, 249]] as $index => [$credits, $price]) {
            DB::table('ai_credit_packages')->insertOrIgnore([
                'name' => number_format($credits) . ' AI credits', 'credits' => $credits, 'price_sar' => $price,
                'is_active' => true, 'sort_order' => $index + 1, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_business_settings');
        Schema::dropIfExists('ai_model_price_history');
        Schema::dropIfExists('ai_provider_health');
        Schema::dropIfExists('ai_routing_rules');
        Schema::dropIfExists('ai_usage_logs');
        Schema::dropIfExists('ai_credit_purchases');
        Schema::dropIfExists('ai_credit_packages');
        Schema::dropIfExists('ai_credit_settings');
        Schema::dropIfExists('ai_model_prices');
        Schema::dropIfExists('ai_credit_transactions');
        Schema::dropIfExists('ai_credit_wallets');
    }
};
