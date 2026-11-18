<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('salla_webhook_receipts', function (Blueprint $table) {
            $table->id();
            $table->char('event_key', 64)->unique();
            $table->string('event', 80);
            $table->string('status', 24)->default('processing');
            $table->unsignedInteger('attempts')->default(1);
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'updated_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('salla_webhook_receipts');
    }
};
