<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('business_profiles', 'media_processing_disclosed_at')) {
            Schema::table('business_profiles', function (Blueprint $table): void {
                $table->timestamp('media_processing_disclosed_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('business_profiles', 'media_processing_disclosed_at')) {
            Schema::table('business_profiles', function (Blueprint $table): void {
                $table->dropColumn('media_processing_disclosed_at');
            });
        }
    }
};
