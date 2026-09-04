<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

return new class extends Migration
{
    public function up(): void
    {
        // Fix existing active enrollments where current_step = 0.
        // These were created with the old default and have no matching
        // sequence step (steps are 1-indexed). Migrate them to step 1
        // so queueStepExecution() can find the correct first step.
        //
        // Safe because:
        //   - We only touch active enrollments (status = 'active')
        //   - We only update rows where current_step = 0
        //   - Completed/stopped/failed enrollments are left untouched
        //   - Step 1 always exists on a valid sequence (canEnroll() guards against empty sequences)
        //   - This migration is idempotent: re-running it does nothing (no rows left with step = 0)

        $updated = DB::table('sequence_enrollments')
            ->where('current_step', 0)
            ->where('status', 'active')
            ->update(['current_step' => 1]);

        if ($updated > 0) {
            Log::info("Migration fix_sequence_enrollment_start_step: migrated {$updated} active enrollment(s) from step 0 to step 1");
        }
    }

    public function down(): void
    {
        // Intentionally not reversible — rolling back to step 0 would
        // re-introduce the off-by-one bug on those enrollments.
    }
};
