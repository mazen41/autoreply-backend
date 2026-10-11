<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('business_profiles', function (Blueprint $table): void {
            $table->timestamp('automation_paused_at')->nullable()->index();
        });

        Schema::table('campaigns', function (Blueprint $table): void {
            $table->timestamp('paused_at')->nullable();
        });

        Schema::table('email_campaigns', function (Blueprint $table): void {
            $table->timestamp('paused_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('email_campaigns', function (Blueprint $table): void {
            $table->dropColumn('paused_at');
        });
        Schema::table('campaigns', function (Blueprint $table): void {
            $table->dropColumn('paused_at');
        });
        Schema::table('business_profiles', function (Blueprint $table): void {
            $table->dropIndex(['automation_paused_at']);
            $table->dropColumn('automation_paused_at');
        });
    }
};
