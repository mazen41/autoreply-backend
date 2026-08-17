<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('email_campaign_recipients', function (Blueprint $table) {
            if (!Schema::hasColumn('email_campaign_recipients', 'tracking_token')) {
                $table->string('tracking_token', 64)->nullable()->unique()->after('conversation_id');
            }
        });

        DB::table('email_campaign_recipients')
            ->whereNull('tracking_token')
            ->orderBy('id')
            ->each(function ($recipient) {
                DB::table('email_campaign_recipients')
                    ->where('id', $recipient->id)
                    ->update(['tracking_token' => Str::random(48)]);
            });
    }

    public function down(): void
    {
        Schema::table('email_campaign_recipients', function (Blueprint $table) {
            if (Schema::hasColumn('email_campaign_recipients', 'tracking_token')) {
                $table->dropUnique(['tracking_token']);
                $table->dropColumn('tracking_token');
            }
        });
    }
};
