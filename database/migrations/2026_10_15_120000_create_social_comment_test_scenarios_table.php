<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('social_comment_test_scenarios', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('business_id')->constrained('business_profiles')->cascadeOnDelete();
            $table->foreignId('bot_id')->constrained('bots')->cascadeOnDelete();
            $table->foreignId('channel_id')->constrained('channels')->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name', 120);
            $table->json('input');
            $table->json('expected');
            $table->timestamps();

            $table->index(['business_id', 'bot_id', 'channel_id'], 'social_comment_scenarios_scope');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('social_comment_test_scenarios');
    }
};
