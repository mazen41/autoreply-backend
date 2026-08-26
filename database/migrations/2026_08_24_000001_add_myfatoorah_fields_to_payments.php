<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Add MyFatoorah columns to subscriptions table
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->string('myfatoorah_invoice_id')->nullable()->after('paymob_transaction_id');
            $table->string('myfatoorah_payment_id')->nullable()->after('myfatoorah_invoice_id');
            $table->string('payment_gateway')->default('paymob')->after('myfatoorah_payment_id');
        });

        // Add MyFatoorah columns to payment_intents table
        Schema::table('payment_intents', function (Blueprint $table) {
            $table->string('myfatoorah_invoice_id')->nullable()->after('paymob_transaction_id');
            $table->string('myfatoorah_payment_id')->nullable()->after('myfatoorah_invoice_id');
            $table->string('payment_gateway')->default('paymob')->after('myfatoorah_payment_id');
            $table->json('gateway_response')->nullable()->after('payment_gateway');
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropColumn(['myfatoorah_invoice_id', 'myfatoorah_payment_id', 'payment_gateway']);
        });

        Schema::table('payment_intents', function (Blueprint $table) {
            $table->dropColumn(['myfatoorah_invoice_id', 'myfatoorah_payment_id', 'payment_gateway', 'gateway_response']);
        });
    }
};
