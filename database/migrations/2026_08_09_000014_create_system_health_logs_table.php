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
        Schema::create('system_health_logs', function (Blueprint $table) {
            $table->id();
            $table->string('component'); // 'ai', 'channel', 'queue', 'webhook'
            $table->string('status'); // 'healthy', 'warning', 'critical'
            $table->text('message')->nullable();
            $table->json('details')->nullable();
            $table->boolean('is_resolved')->default(false);
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
            
            $table->index(['component', 'is_resolved']);
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('system_health_logs');
    }
};