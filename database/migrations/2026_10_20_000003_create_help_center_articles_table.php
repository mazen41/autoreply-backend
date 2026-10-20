<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('help_center_articles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained('business_profiles')->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title', 191);
            $table->string('slug', 191);
            $table->string('category', 100)->default('General');
            $table->string('excerpt', 500)->nullable();
            $table->longText('content');
            $table->string('status', 20)->default('draft');
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->unique(['business_id', 'slug']);
            $table->index(['business_id', 'status', 'category']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('help_center_articles');
    }
};
