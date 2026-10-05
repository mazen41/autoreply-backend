<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_message_maps', function (Blueprint $table) {
            $table->foreignId('commerce_channel_id')->nullable()->after('salla_product_id')->constrained('channels')->nullOnDelete();
            $table->string('commerce_external_id')->nullable()->after('commerce_channel_id');
        });
    }

    public function down(): void
    {
        Schema::table('product_message_maps', function (Blueprint $table) {
            $table->dropConstrainedForeignId('commerce_channel_id');
            $table->dropColumn('commerce_external_id');
        });
    }
};
