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
        Schema::create('csat_ratings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained('business_profiles')->onDelete('cascade');
            $table->foreignId('conversation_id')->constrained()->onDelete('cascade');
            $table->foreignId('user_id')->nullable()->constrained()->onDelete('set null');
            $table->enum('rating', ['positive', 'negative'])->nullable();
            $table->text('feedback')->nullable();
            $table->timestamp('rated_at')->nullable();
            $table->timestamps();
            
            $table->index(['business_id', 'rated_at']);
            $table->index('rating');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('csat_ratings');
    }
};