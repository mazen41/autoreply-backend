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
        Schema::create('message_feedbacks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('message_id')->constrained()->onDelete('cascade');
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->enum('feedback', ['positive', 'negative'])->nullable();
            $table->text('comment')->nullable();
            $table->enum('issue_type', ['inaccurate', 'inappropriate', 'off_topic', 'poor_quality', 'other'])->nullable();
            $table->float('confidence_score')->nullable();
            $table->string('detected_dialect')->nullable(); // egyptian, gulf, msa, etc.
            $table->timestamps();
            
            $table->index(['message_id', 'user_id']);
            $table->index('feedback');
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('message_feedbacks');
    }
};