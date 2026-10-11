<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\BusinessProfile;
use App\Services\UsageMeter;

class CheckUsageLimits extends Command
{
    protected $signature = 'usage:check';
    protected $description = 'Check and enforce usage limits for all businesses';

    public function __construct()
    {
        parent::__construct();
    }

    public function handle(UsageMeter $usageMeter)
    {
        $this->info('Checking usage limits...');

        $businesses = BusinessProfile::query()->with('user')->get();

        foreach ($businesses as $business) {
            try {
                $snapshot = $usageMeter->snapshot($business);
                $parts = [];
                foreach ($snapshot['usage'] as $metric => $usage) {
                    $limit = $usage['unlimited'] ? 'unlimited' : (string) $usage['limit'];
                    $parts[] = "{$metric}={$usage['used']}/{$limit}";
                    if (!$usage['allowed']) {
                        $this->warn("Business {$business->id} reached {$metric} limit ({$usage['used']}/{$limit})");
                    }
                }
                $this->info("Business {$business->id} [{$snapshot['plan']['name']}]: " . implode(', ', $parts));

            } catch (\Exception $e) {
                $this->error("Failed to check usage for business ID {$business->id}: {$e->getMessage()}");
            }
        }

        $this->info('Usage limits check completed.');
        return Command::SUCCESS;
    }
}
