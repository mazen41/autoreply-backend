<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Local record of a payment attempt, created before redirecting the
     * customer to Paymob. Paymob's own metadata/extras echoing behavior is
     * inconsistent/undocumented across response shapes, so instead we pass
     * this row's ID as Paymob's `special_reference`, which IS reliably
     * echoed back on the transaction under `merchant_order_id`. The
     * callback/webhook look this row up by that ID instead of trying to
     * parse metadata out of Paymob's response.
     */
    public function up(): void
    {
        Schema::create('payment_intents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('package_id')->constrained()->cascadeOnDelete();
            $table->enum('billing_cycle', ['monthly', 'yearly']);
            $table->string('status')->default('pending'); // pending|completed|failed
            $table->string('paymob_order_id')->nullable();
            $table->string('paymob_transaction_id')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('paymob_order_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_intents');
    }
};
