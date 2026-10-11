<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contact_consents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained('business_profiles')->cascadeOnDelete();
            $table->string('channel', 32);
            $table->string('contact_identifier', 255);
            $table->enum('scope', ['marketing', 'service']);
            $table->enum('status', ['opted_in', 'opted_out', 'unknown'])->default('unknown');
            $table->string('source', 40);
            $table->timestamp('captured_at')->nullable();
            $table->text('evidence')->nullable();
            $table->char('ip_hash', 64)->nullable();
            $table->timestamps();
            $table->unique(['business_id', 'channel', 'contact_identifier', 'scope'], 'contact_consent_identity_unique');
            $table->index(['business_id', 'channel', 'status']);
        });

        Schema::create('suppressions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained('business_profiles')->cascadeOnDelete();
            $table->string('channel', 32);
            $table->string('contact_identifier', 255);
            $table->enum('reason', ['unsubscribed', 'bounced', 'complained']);
            $table->string('source', 40);
            $table->timestamp('suppressed_at');
            $table->timestamps();
            $table->unique(['business_id', 'channel', 'contact_identifier', 'reason'], 'suppression_identity_unique');
            $table->index(['business_id', 'channel', 'suppressed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('suppressions');
        Schema::dropIfExists('contact_consents');
    }
};
