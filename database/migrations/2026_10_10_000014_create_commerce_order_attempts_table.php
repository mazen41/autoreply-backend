<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commerce_order_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained('business_profiles')->cascadeOnDelete();
            $table->foreignId('conversation_id')->constrained('conversations')->cascadeOnDelete();
            $table->foreignId('connection_id')->constrained('channels')->cascadeOnDelete();
            $table->string('provider', 32);
            $table->char('checkout_fingerprint', 64);
            $table->char('idempotency_key', 64)->unique();
            $table->string('status', 24)->default('pending');
            $table->string('external_order_id')->nullable();
            $table->string('order_number')->nullable();
            $table->timestamps();
            $table->index(['conversation_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commerce_order_attempts');
    }
};
