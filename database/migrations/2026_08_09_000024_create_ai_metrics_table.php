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
        Schema::create('ai_metrics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained('business_profiles')->onDelete('cascade');
            $table->date('date')->unique();
            $table->integer('total_ai_messages')->default(0);
            $table->integer('successful_ai_messages')->default(0);
            $table->integer('escalated_messages')->default(0);
            $table->decimal('avg_confidence_score', 5, 2)->default(0);
            $table->integer('positive_feedback')->default(0);
            $table->integer('negative_feedback')->default(0);
            $table->decimal('success_rate', 5, 2)->default(0);
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
        Schema::dropIfExists('ai_metrics');
    }
};