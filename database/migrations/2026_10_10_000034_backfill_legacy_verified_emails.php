<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('legacy_email_verification_backfills', function (Blueprint $table): void {
            $table->unsignedBigInteger('user_id')->primary();
            $table->timestamp('backfilled_at');
        });

        // At deploy time, these null values belong to accounts that already
        // existed before login began enforcing email verification.
        $backfilledAt = now()->subSecond();
        DB::table('users')->whereNull('email_verified_at')->orderBy('id')->chunkById(500, function ($users) use ($backfilledAt): void {
            foreach ($users as $user) {
                DB::table('legacy_email_verification_backfills')->insert([
                    'user_id' => $user->id,
                    'backfilled_at' => $backfilledAt,
                ]);
                DB::table('users')->where('id', $user->id)->whereNull('email_verified_at')->update([
                    'email_verified_at' => $backfilledAt,
                ]);
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('legacy_email_verification_backfills')) {
            return;
        }

        DB::table('legacy_email_verification_backfills')->orderBy('user_id')->chunk(500, function ($rows): void {
            foreach ($rows as $row) {
                DB::table('users')->where('id', $row->user_id)
                    ->where('email_verified_at', $row->backfilled_at)
                    ->update(['email_verified_at' => null]);
            }
        });

        Schema::drop('legacy_email_verification_backfills');
    }
};
