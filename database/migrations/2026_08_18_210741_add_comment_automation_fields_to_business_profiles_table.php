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
            // Comment Automation Settings
            $table->boolean('comment_automation_enabled')->default(false);
            $table->boolean('instagram_comments_enabled')->default(false);
            $table->boolean('facebook_comments_enabled')->default(false);
            $table->string('reply_mode', 20)->default('public_comment'); // public_comment, public_reply_private_message, private_message
            $table->integer('confidence_threshold')->default(70); // 0-100
            $table->string('reply_language', 20)->default('automatic'); // automatic, arabic, english, same_as_customer
            $table->integer('max_reply_length')->default(200); // maximum characters
            $table->boolean('use_knowledge')->default(true);
            $table->boolean('use_products')->default(true);
            $table->boolean('use_prices')->default(true);
            $table->boolean('use_inventory')->default(true);
            $table->boolean('use_orders')->default(true);
            $table->boolean('use_shipping')->default(true);
            $table->boolean('use_policies')->default(true);
            $table->boolean('ignore_spam')->default(true);
            $table->boolean('ignore_offensive')->default(true);
            $table->boolean('ignore_competitors')->default(true);
            $table->text('blocked_keywords')->nullable(); // JSON array or comma-separated
            $table->boolean('emoji_enabled')->default(true);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('business_profiles', function (Blueprint $table) {
            $table->dropColumn([
                'comment_automation_enabled',
                'instagram_comments_enabled',
                'facebook_comments_enabled',
                'reply_mode',
                'confidence_threshold',
                'reply_language',
                'max_reply_length',
                'use_knowledge',
                'use_products',
                'use_prices',
                'use_inventory',
                'use_orders',
                'use_shipping',
                'use_policies',
                'ignore_spam',
                'ignore_offensive',
                'ignore_competitors',
                'blocked_keywords',
                'emoji_enabled'
            ]);
        });
    }
};