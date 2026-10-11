<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('business_profiles', function (Blueprint $table): void {
            if (!Schema::hasColumn('business_profiles', 'estimated_manual_reply_minutes')) {
                $table->unsignedSmallInteger('estimated_manual_reply_minutes')->default(3);
            }
            if (!Schema::hasColumn('business_profiles', 'estimated_hourly_rate')) {
                $table->decimal('estimated_hourly_rate', 10, 2)->default(100);
            }
            if (!Schema::hasColumn('business_profiles', 'estimated_hourly_rate_currency')) {
                $table->string('estimated_hourly_rate_currency', 3)->default('SAR');
            }
        });
    }

    public function down(): void
    {
        Schema::table('business_profiles', function (Blueprint $table): void {
            $columns = [];
            foreach (['estimated_manual_reply_minutes', 'estimated_hourly_rate', 'estimated_hourly_rate_currency'] as $column) {
                if (Schema::hasColumn('business_profiles', $column)) $columns[] = $column;
            }
            if ($columns !== []) $table->dropColumn($columns);
        });
    }
};
