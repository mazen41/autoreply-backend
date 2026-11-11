<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'central_payments';

    public function up(): void
    {
        Schema::connection($this->connection)->create('payment_clients', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->string('slug', 80)->unique();
            $table->string('name');
            $table->char('api_key_hash', 64);
            $table->text('hmac_secret');
            $table->text('notification_secret');
            $table->string('backend_url', 500);
            $table->string('frontend_url', 500);
            $table->boolean('is_active')->default(true)->index();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::connection($this->connection)->create('central_payments', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('payment_client_id')->constrained('payment_clients')->restrictOnDelete();
            $table->string('external_order_id', 160);
            $table->string('external_customer_id', 160)->nullable();
            $table->string('external_package_id', 160)->nullable();
            $table->string('package_name')->nullable();
            $table->string('customer_email')->nullable();
            $table->string('customer_name')->nullable();
            $table->decimal('amount', 12, 2);
            $table->char('currency', 3);
            $table->string('status', 32)->default('creating')->index();
            $table->string('idempotency_key', 160);
            $table->string('myfatoorah_invoice_id', 80)->nullable()->unique();
            $table->string('myfatoorah_payment_id', 80)->nullable()->index();
            $table->text('checkout_url')->nullable();
            $table->json('customer_details')->nullable();
            $table->json('verified_order')->nullable();
            $table->json('gateway_metadata')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
            $table->unique(['payment_client_id', 'idempotency_key'], 'central_payment_idempotency_unique');
            $table->index(['payment_client_id', 'external_order_id']);
        });

        Schema::connection($this->connection)->create('central_payment_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('central_payment_id')->constrained('central_payments')->cascadeOnDelete();
            $table->string('event_reference', 190)->unique();
            $table->string('event_type', 80);
            $table->json('payload')->nullable();
            $table->string('processing_status', 32)->default('received')->index();
            $table->text('failure_reason')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
        });

        Schema::connection($this->connection)->create('central_payment_nonces', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_client_id')->constrained('payment_clients')->cascadeOnDelete();
            $table->string('nonce_hash', 64);
            $table->timestamp('expires_at')->index();
            $table->timestamps();
            $table->unique(['payment_client_id', 'nonce_hash'], 'central_payment_nonce_unique');
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('central_payment_nonces');
        Schema::connection($this->connection)->dropIfExists('central_payment_events');
        Schema::connection($this->connection)->dropIfExists('central_payments');
        Schema::connection($this->connection)->dropIfExists('payment_clients');
    }
};
