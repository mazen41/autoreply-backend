<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $duplicates = DB::table('channels')
            ->select('page_id', DB::raw('COUNT(*) as row_count'))
            ->where('type', 'salla')
            ->whereNotNull('page_id')
            ->groupBy('page_id')
            ->havingRaw('COUNT(*) > 1')
            ->limit(10)
            ->get();

        if ($duplicates->isNotEmpty()) {
            throw new RuntimeException(
                'Cannot add the Salla merchant uniqueness constraint while duplicate merchant rows exist. '
                .'Review and reconcile channel relationships before retrying this migration.'
            );
        }

        Schema::table('channels', function (Blueprint $table): void {
            // Non-Salla channels yield NULL, so only Salla merchant IDs are unique.
            // Do not delete or merge tenant records automatically in a migration.
            $table->string('salla_page_id')->nullable()
                ->storedAs("CASE WHEN type = 'salla' THEN page_id ELSE NULL END");
            $table->unique('salla_page_id', 'channels_salla_page_id_unique');
        });
    }

    public function down(): void
    {
        Schema::table('channels', function (Blueprint $table): void {
            $table->dropUnique('channels_salla_page_id_unique');
            $table->dropColumn('salla_page_id');
        });
    }
};
