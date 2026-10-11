<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\AnalyticsService;
use App\Models\BusinessProfile;

class DailyAnalytics extends Command
{
    protected $signature = 'analytics:daily';
    protected $description = 'Generate daily analytics reports for all businesses';

    public function __construct()
    {
        parent::__construct();
    }

    public function handle()
    {
        $this->info('Starting daily analytics generation...');

        $businesses = BusinessProfile::all();
        $analyticsService = new AnalyticsService();
        $failedBusinessIds = [];

        foreach ($businesses as $business) {
            try {
                $analyticsService->generateDailyReport($business->id);
                $this->info("Generated daily report for business ID: {$business->id}");
            } catch (\Throwable $e) {
                $this->error("Failed to generate report for business ID {$business->id}: {$e->getMessage()}");
                $failedBusinessIds[] = $business->id;
            }
        }

        if ($failedBusinessIds !== []) {
            $this->error('Daily analytics failed for business ID(s): ' . implode(', ', $failedBusinessIds));
            return Command::FAILURE;
        }

        $this->info('Daily analytics generation completed.');
        return Command::SUCCESS;
    }
}
