<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('email_campaign_recipients', function (Blueprint $table) {
            if (!Schema::hasColumn('email_campaign_recipients', 'clicked_at')) {
                $table->dateTime('clicked_at')->nullable()->after('opened_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('email_campaign_recipients', function (Blueprint $table) {
            if (Schema::hasColumn('email_campaign_recipients', 'clicked_at')) {
                $table->dropColumn('clicked_at');
            }
        });
    }
};
