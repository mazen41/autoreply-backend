<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->integer('retry_count')->default(0)->after('send_status');
            $table->timestamp('last_retry_at')->nullable()->after('retry_count');
            $table->enum('delivery_status', ['pending', 'sent', 'delivered', 'failed'])->default('pending')->after('send_status');
            $table->text('error_details')->nullable()->after('delivery_status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropColumn(['retry_count', 'last_retry_at', 'delivery_status', 'error_details']);
        });
    }
};