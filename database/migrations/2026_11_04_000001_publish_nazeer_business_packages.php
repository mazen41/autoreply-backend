<?php

use App\Models\Package;
use Database\Seeders\PackageSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('packages', 'max_team_members')) {
            Schema::table('packages', function (Blueprint $table): void {
                $table->integer('max_team_members')->default(-1);
            });
        }

        // Package definitions include their monthly AI allowance, so the
        // column must exist before the catalog upsert runs. The credit-engine
        // migration also guards this addition for installations that already
        // have the column.
        if (!Schema::hasColumn('packages', 'ai_credits_monthly')) {
            Schema::table('packages', function (Blueprint $table): void {
                $table->unsignedBigInteger('ai_credits_monthly')->default(0);
            });
        }

        DB::transaction(function (): void {
            $legacyPro = Package::query()->where('name', 'Pro')->first();

            if ($legacyPro) {
                // Keep existing Pro subscriptions attached to their original
                // commercial terms; publish Growth as a separate plan.
                $legacyPro->update(['is_active' => false]);
            }

            foreach (PackageSeeder::definitions() as $name => $attributes) {
                Package::query()->updateOrCreate(['name' => $name], $attributes + ['name' => $name]);
            }
        });
    }

    public function down(): void
    {
        // Package records can have live subscriptions; keep the catalog in place on rollback.
        if (Schema::hasColumn('packages', 'max_team_members')) {
            Schema::table('packages', function (Blueprint $table): void {
                $table->dropColumn('max_team_members');
            });
        }
    }
};
