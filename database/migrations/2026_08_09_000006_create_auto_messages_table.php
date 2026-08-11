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
        Schema::create('auto_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained('business_profiles')->onDelete('cascade');
            $table->enum('type', ['away', 'holiday', 'welcome'])->default('away');
            $table->text('message');
            $table->boolean('is_enabled')->default(true);
            $table->string('timezone')->default('UTC');
            $table->timestamps();
            
            $table->index(['business_id', 'type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('auto_messages');
    }
};