<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_tour_states', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('business_id')->constrained('business_profiles')->cascadeOnDelete();
            $table->string('status', 24)->default('not_started');
            $table->unsignedSmallInteger('current_step')->default(0);
            $table->timestamps();
            $table->unique(['user_id', 'business_id']);
            $table->index(['business_id', 'status']);
        });

        // Existing workspaces should not receive a first-login modal just because
        // the tour feature was deployed. New user/workspace pairs remain eligible.
        $now = now();
        DB::table('business_profiles')->select(['id', 'user_id'])->orderBy('id')->chunk(500, function ($businesses) use ($now) {
            DB::table('product_tour_states')->insertOrIgnore($businesses->map(fn ($business) => [
                'user_id' => $business->user_id,
                'business_id' => $business->id,
                'status' => 'skipped',
                'current_step' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ])->all());
        });

        DB::table('team_members')->where('is_active', true)->select(['user_id', 'business_id'])
            ->orderBy('id')->chunk(500, function ($members) use ($now) {
                DB::table('product_tour_states')->insertOrIgnore($members->map(fn ($member) => [
                    'user_id' => $member->user_id,
                    'business_id' => $member->business_id,
                    'status' => 'skipped',
                    'current_step' => 0,
                    'created_at' => $now,
                    'updated_at' => $now,
                ])->all());
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_tour_states');
    }
};
