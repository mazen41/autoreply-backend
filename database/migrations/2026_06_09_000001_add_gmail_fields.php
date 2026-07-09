<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::table('messages', function (Blueprint $table) {
            $table->string('gmail_message_id')->nullable()->unique()->after('is_ai');
        });
        Schema::table('conversations', function (Blueprint $table) {
            $table->string('subject')->nullable()->after('sender_id');
            $table->string('sender_email')->nullable()->after('subject');
        });
    }
    public function down(): void {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropColumn('gmail_message_id');
        });
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropColumn(['subject','sender_email']);
        });
    }
};