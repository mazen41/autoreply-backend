<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_coaching_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('conversation_id');
            $table->unsignedBigInteger('agent_id')->nullable();
            $table->unsignedBigInteger('business_id')->nullable();
            $table->unsignedTinyInteger('empathy_score')->default(5);
            $table->unsignedTinyInteger('sla_adherence_score')->default(5);
            $table->unsignedTinyInteger('accuracy_score')->default(5);
            $table->unsignedTinyInteger('overall_score')->default(5);
            $table->text('constructive_feedback')->nullable();
            $table->timestamps();

            $table->foreign('conversation_id')->references('id')->on('conversations')->onDelete('cascade');
            $table->foreign('agent_id')->references('id')->on('users')->onDelete('set null');
            $table->index(['agent_id', 'created_at'], 'agent_coaching_agent_created_index');
            $table->index(['business_id', 'created_at'], 'agent_coaching_business_created_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_coaching_logs');
    }
};
