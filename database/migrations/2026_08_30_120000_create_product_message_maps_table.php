<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CRITICAL ISSUE — REPLY-TO-PRODUCT CONTEXT / PRODUCT SELECTION
 *
 * Persists a deterministic mapping between every outgoing WhatsApp
 * product-image message and the Salla product it depicts, so that when a
 * customer replies directly to one of those images ("I wanna place order
 * for this one"), the backend can resolve the EXACT product referenced —
 * without ever asking the AI to guess from text/position/name similarity.
 *
 * whatsapp_message_id is the Evolution `key.id` returned when the image
 * was sent (or the platform-native message id for Facebook/Instagram).
 * conversation_id scopes lookups so ids from different conversations can
 * never collide.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_message_maps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained()->onDelete('cascade');
            $table->foreignId('channel_id')->nullable()->constrained()->nullOnDelete();

            // The outgoing message id for the specific product image that was
            // sent (Evolution API key.id for WhatsApp, message_id for
            // Facebook/Instagram Graph API).
            $table->string('whatsapp_message_id')->index();

            // Salla product identity — kept as relational references rather
            // than duplicating the full product JSON.
            $table->string('salla_product_id')->nullable()->index();
            $table->string('sku')->nullable();
            $table->string('product_name')->nullable();
            $table->string('product_price')->nullable();
            $table->string('currency')->nullable();
            $table->text('image_url')->nullable();

            $table->timestamps();

            $table->unique(['conversation_id', 'whatsapp_message_id'], 'product_message_maps_conv_msg_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_message_maps');
    }
};
