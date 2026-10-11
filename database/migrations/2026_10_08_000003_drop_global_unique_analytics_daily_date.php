<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('analytics_daily', function (Blueprint $table) {
            try {
                $table->dropUnique('analytics_daily_date_unique');
            } catch (\Throwable $e) {
                // The legacy global date index may already be absent.
            }
        });
    }

    public function down(): void
    {
        $hasDuplicateDates = DB::table('analytics_daily')
            ->select('date')
            ->groupBy('date')
            ->havingRaw('COUNT(*) > 1')
            ->exists();

        if (!$hasDuplicateDates) {
            Schema::table('analytics_daily', function (Blueprint $table) {
                $table->unique('date');
            });
        }
    }
};
