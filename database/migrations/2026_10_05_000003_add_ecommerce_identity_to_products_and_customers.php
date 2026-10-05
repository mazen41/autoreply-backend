<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->foreignId('commerce_channel_id')->nullable()->after('business_id')->constrained('channels')->cascadeOnDelete();
            $table->string('commerce_external_id')->nullable()->after('sku');
            $table->unique(['commerce_channel_id', 'commerce_external_id'], 'products_commerce_identity_unique');
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->foreignId('commerce_channel_id')->nullable()->after('business_profile_id')->constrained('channels')->cascadeOnDelete();
            $table->string('commerce_external_id')->nullable()->after('email');
            $table->unique(['commerce_channel_id', 'commerce_external_id'], 'customers_commerce_identity_unique');
        });

        Schema::create('commerce_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained('business_profiles')->cascadeOnDelete();
            $table->foreignId('channel_id')->constrained('channels')->cascadeOnDelete();
            $table->string('external_id');
            $table->string('order_number')->nullable();
            $table->string('status')->nullable();
            $table->string('fulfillment_status')->nullable();
            $table->decimal('total', 14, 2)->default(0);
            $table->string('currency', 8)->nullable();
            $table->string('customer_name')->nullable();
            $table->string('customer_email')->nullable();
            $table->string('customer_phone')->nullable();
            $table->text('shipping_address')->nullable();
            $table->json('line_items')->nullable();
            $table->json('raw_data')->nullable();
            $table->timestamp('ordered_at')->nullable();
            $table->timestamps();
            $table->unique(['channel_id', 'external_id'], 'commerce_orders_channel_external_unique');
            $table->index(['business_id', 'ordered_at']);
        });

        Schema::create('commerce_webhook_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('channel_id')->constrained('channels')->cascadeOnDelete();
            $table->string('provider', 32);
            $table->string('event_id', 191);
            $table->string('topic', 100)->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
            $table->unique(['provider', 'channel_id', 'event_id'], 'commerce_webhook_delivery_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commerce_webhook_deliveries');
        Schema::dropIfExists('commerce_orders');
        Schema::table('customers', function (Blueprint $table) {
            $table->dropUnique('customers_commerce_identity_unique');
            $table->dropConstrainedForeignId('commerce_channel_id');
            $table->dropColumn('commerce_external_id');
        });
        Schema::table('products', function (Blueprint $table) {
            $table->dropUnique('products_commerce_identity_unique');
            $table->dropConstrainedForeignId('commerce_channel_id');
            $table->dropColumn('commerce_external_id');
        });
    }
};
