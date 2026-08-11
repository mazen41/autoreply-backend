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
        Schema::create('analytics_daily', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained('business_profiles')->onDelete('cascade');
            $table->date('date')->unique();
            $table->integer('total_conversations')->default(0);
            $table->integer('total_messages')->default(0);
            $table->integer('ai_messages')->default(0);
            $table->integer('human_messages')->default(0);
            $table->integer('resolved_conversations')->default(0);
            $table->decimal('avg_response_time_seconds', 10, 2)->default(0);
            $table->integer('new_users')->default(0);
            $table->integer('returning_users')->default(0);
            $table->timestamps();
            
            $table->unique(['business_id', 'date']);
            $table->index('date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('analytics_daily');
    }
};