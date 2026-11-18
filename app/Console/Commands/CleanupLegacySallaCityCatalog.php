<?php

namespace App\Console\Commands;

use App\Models\SallaCityCatalogSync;
use App\Services\SharedSallaCityCatalogService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CleanupLegacySallaCityCatalog extends Command
{
    protected $signature = 'salla:cities:cleanup-legacy {--apply : Reset interrupted legacy syncs and delete old per-channel city rows}';
    protected $description = 'Review or clean the old per-channel Salla city catalog after verified shared cutover';

    public function handle(SharedSallaCityCatalogService $catalogs): int
    {
        if (!$catalogs->allConfiguredCatalogsVerified()) {
            $this->error('Cleanup blocked: each configured shared catalog must be fresh and pass second-store ID-set verification.');
            return self::FAILURE;
        }

        $cityRows = (int) DB::table('salla_cities')->count();
        $runningSyncs = SallaCityCatalogSync::query()->where('status', 'running')->count();
        $this->info("Legacy per-channel city rows: {$cityRows}");
        $this->info("Interrupted legacy sync records to reset: {$runningSyncs}");

        if (!$this->option('apply')) {
            $this->comment('Dry run only. Repeat with --apply after reviewing these counts.');
            return self::SUCCESS;
        }

        DB::transaction(function () use ($cityRows): void {
            SallaCityCatalogSync::query()->where('status', 'running')->update([
                'status' => 'failed',
                'last_error_code' => 'shared_catalog_cutover',
                'last_error_message' => 'Legacy per-channel synchronization was stopped after the shared catalog cutover.',
                'updated_at' => now(),
            ]);

            if ($cityRows > 0) {
                DB::table('salla_cities')->delete();
            }
        });

        $this->info('Legacy per-channel city rows were removed; sync history was retained.');
        return self::SUCCESS;
    }
}
