<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Renames Moyasar-specific columns to Paymob equivalents in the subscriptions table.
     */
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            // Rename moyasar_payment_id → paymob_transaction_id
            if (Schema::hasColumn('subscriptions', 'moyasar_payment_id')) {
                $table->renameColumn('moyasar_payment_id', 'paymob_transaction_id');
            } elseif (!Schema::hasColumn('subscriptions', 'paymob_transaction_id')) {
                $table->string('paymob_transaction_id')->nullable();
            }

            // Rename moyasar_invoice_id → paymob_order_id
            if (Schema::hasColumn('subscriptions', 'moyasar_invoice_id')) {
                $table->renameColumn('moyasar_invoice_id', 'paymob_order_id');
            } elseif (!Schema::hasColumn('subscriptions', 'paymob_order_id')) {
                $table->string('paymob_order_id')->nullable();
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            if (Schema::hasColumn('subscriptions', 'paymob_transaction_id')) {
                $table->renameColumn('paymob_transaction_id', 'moyasar_payment_id');
            }

            if (Schema::hasColumn('subscriptions', 'paymob_order_id')) {
                $table->renameColumn('paymob_order_id', 'moyasar_invoice_id');
            }
        });
    }
};
