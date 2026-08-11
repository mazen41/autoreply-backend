<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\SystemHealthMonitoringService;
use Illuminate\Support\Facades\Log;

class SystemHealthCheck extends Command
{
    protected $signature = 'system:health-check';
    protected $description = 'Perform system health checks and alert on issues';

    public function __construct()
    {
        parent::__construct();
    }

    public function handle()
    {
        $this->info('Starting system health check...');

        $healthService = new SystemHealthMonitoringService();

        try {
            $healthStatus = $healthService->checkSystemHealth();

            if ($healthStatus['status'] === 'healthy') {
                $this->info('System is healthy.');
            } else {
                $this->warn('System health issues detected:');
                
                foreach ($healthStatus['issues'] as $issue) {
                    $this->error("- {$issue['component']}: {$issue['message']}");
                    
                    // Log critical issues
                    if ($issue['severity'] === 'critical') {
                        Log::critical("System health issue: {$issue['component']} - {$issue['message']}");
                    }
                }
            }

            $this->info('System health check completed.');
            return Command::SUCCESS;

        } catch (\Exception $e) {
            $this->error("Health check failed: {$e->getMessage()}");
            Log::error("System health check failed: {$e->getMessage()}");
            return Command::FAILURE;
        }
    }
}