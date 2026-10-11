<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campaign_logs', function (Blueprint $table) {
            $table->string('status', 20)->default('queued')->change();
        });
    }

    public function down(): void
    {
        DB::table('campaign_logs')->where('status', 'sending')->update(['status' => 'queued']);
        Schema::table('campaign_logs', function (Blueprint $table) {
            $table->enum('status', ['queued', 'sent', 'failed'])->default('queued')->change();
        });
    }
};
