<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_intents', function (Blueprint $table): void {
            $table->boolean('auto_renew_requested')->default(false);
            $table->timestamp('auto_renew_consented_at')->nullable();
            $table->string('myfatoorah_recurring_id')->nullable()->index();
        });

        Schema::table('subscriptions', function (Blueprint $table): void {
            $table->boolean('auto_renew_enabled')->default(false);
            $table->timestamp('auto_renew_consented_at')->nullable();
            $table->string('myfatoorah_recurring_id')->nullable()->index();
        });

        Schema::create('subscription_renewal_invoices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('subscription_id')->constrained()->cascadeOnDelete();
            $table->string('gateway', 32);
            $table->string('external_invoice_id');
            $table->string('external_transaction_id')->nullable();
            $table->decimal('amount', 12, 2)->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
            $table->unique(['gateway', 'external_invoice_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_renewal_invoices');

        Schema::table('subscriptions', function (Blueprint $table): void {
            $table->dropIndex(['myfatoorah_recurring_id']);
            $table->dropColumn(['auto_renew_enabled', 'auto_renew_consented_at', 'myfatoorah_recurring_id']);
        });

        Schema::table('payment_intents', function (Blueprint $table): void {
            $table->dropIndex(['myfatoorah_recurring_id']);
            $table->dropColumn(['auto_renew_requested', 'auto_renew_consented_at', 'myfatoorah_recurring_id']);
        });
    }
};
