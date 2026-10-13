<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('social_comment_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('channel_id')->constrained('channels')->cascadeOnDelete();
            $table->foreignId('business_id')->constrained('business_profiles')->cascadeOnDelete();
            $table->foreignId('bot_id')->nullable()->constrained('bots')->nullOnDelete();
            $table->string('platform', 24);
            $table->string('event_type', 32)->default('comment_created');
            $table->string('account_id', 120);
            $table->string('post_id', 160)->nullable();
            $table->string('comment_id', 160);
            $table->string('parent_comment_id', 160)->nullable();
            $table->string('commenter_id', 160)->nullable();
            $table->text('comment_text')->nullable();
            $table->timestamp('platform_created_at')->nullable();
            $table->boolean('is_live_comment')->default(false);
            $table->json('payload')->nullable();
            $table->string('status', 24)->default('received');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->string('failure_code', 64)->nullable();
            $table->text('failure_reason')->nullable();
            $table->string('public_reply_id', 160)->nullable();
            $table->string('private_reply_id', 160)->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->unique(['platform', 'channel_id', 'comment_id', 'event_type'], 'social_comment_event_dedupe');
            $table->index(['business_id', 'status', 'created_at'], 'social_comment_event_queue');
        });

        Schema::table('bot_channels', function (Blueprint $table): void {
            $table->boolean('comment_automation_enabled')->default(false);
            $table->boolean('public_comment_reply_enabled')->default(true);
            $table->boolean('private_comment_reply_enabled')->default(false);
            $table->json('comment_settings')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('bot_channels', function (Blueprint $table): void {
            $table->dropColumn([
                'comment_automation_enabled',
                'public_comment_reply_enabled',
                'private_comment_reply_enabled',
                'comment_settings',
            ]);
        });
        Schema::dropIfExists('social_comment_events');
    }
};
