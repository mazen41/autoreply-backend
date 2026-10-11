<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campaigns', function (Blueprint $table) {
            $table->string('status', 20)->default('draft')->change();
        });
    }

    public function down(): void
    {
        DB::table('campaigns')->where('status', 'partially_sent')->update(['status' => 'sent']);
        Schema::table('campaigns', function (Blueprint $table) {
            $table->enum('status', ['draft', 'scheduled', 'sending', 'sent', 'failed'])->default('draft')->change();
        });
    }
};
