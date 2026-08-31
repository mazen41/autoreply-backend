<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Persist partial checkout state across conversation turns.
 *
 * When a customer is mid-way through the order collection flow
 * (product known, bot is collecting name/phone/address), this column
 * stores the gathered data so that if ANY downstream call fails (Salla API
 * timeout, token expiry, etc.) the bot can resume exactly where it left off
 * on the customer's next message instead of asking them to start over.
 *
 * Schema: JSON object with nullable fields:
 * {
 *   "salla_product_id": "523147668",
 *   "product_name": "Dress",
 *   "product_price": 174,
 *   "product_currency": "SAR",
 *   "customer_name": "Mazen Hossny",
 *   "customer_phone": "201152879755",
 *   "customer_address": "123 Nile St",
 *   "customer_city": "Cairo",
 *   "quantity": 1,
 *   "notes": null,
 *   "started_at": "2026-08-31T08:37:37Z"
 * }
 *
 * Cleared (set to null) when the order is confirmed/placed or the conversation is closed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->json('checkout_state')->nullable()->after('ai_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropColumn('checkout_state');
        });
    }
};
