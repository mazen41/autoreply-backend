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
        Schema::create('packages', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('name_ar');
            $table->text('description')->nullable();
            $table->text('description_ar')->nullable();
            $table->decimal('price_monthly', 8, 2);
            $table->decimal('price_yearly', 8, 2);
            $table->integer('ai_replies_limit')->default(-1);
            $table->integer('channels_limit')->default(-1);
            $table->integer('tools_limit')->default(-1);
            $table->integer('blog_posts_limit')->default(-1);
            $table->json('features')->nullable();
            $table->json('features_ar')->nullable();
            $table->boolean('is_popular')->default(false);
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('packages');
    }
};
