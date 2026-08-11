<?php

namespace App\Services;

use App\Models\SystemHealthLog;
use App\Models\Channel;
use App\Models\Subscription;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

class SystemHealthMonitoringService
{
    /**
     * Run comprehensive health check
     */
    public function runHealthCheck(): array
    {
        $health = [
            'overall_status' => 'healthy',
            'components' => [],
            'timestamp' => now()->toISOString(),
        ];

        // Check AI Service
        $aiHealth = $this->checkAIService();
        $health['components']['ai'] = $aiHealth;

        // Check Channels
        $channelHealth = $this->checkChannels();
        $health['components']['channels'] = $channelHealth;

        // Check Queue
        $queueHealth = $this->checkQueue();
        $health['components']['queue'] = $queueHealth;

        // Check Database
        $dbHealth = $this->checkDatabase();
        $health['components']['database'] = $dbHealth;

        // Check Webhook Delivery
        $webhookHealth = $this->checkWebhookDelivery();
        $health['components']['webhooks'] = $webhookHealth;

        // Determine overall status
        $statuses = array_column($health['components'], 'status');
        
        if (in_array('critical', $statuses)) {
            $health['overall_status'] = 'critical';
        } elseif (in_array('warning', $statuses)) {
            $health['overall_status'] = 'warning';
        }

        // Log health check result
        $this->logHealthCheck($health);

        return $health;
    }

    /**
     * Check AI Service health
     */
    private function checkAIService(): array
    {
        $health = [
            'status' => 'healthy',
            'message' => 'AI service operational',
            'details' => [],
        ];

        try {
            // Check if AI is responding
            $testResponse = $this->testAIService();
            
            if (!$testResponse['success']) {
                $health['status'] = 'critical';
                $health['message'] = 'AI service not responding';
                $health['details']['error'] = $testResponse['error'];
            }

            // Check AI confidence scores
            $recentConfidence = Cache::get('recent_ai_confidence', []);
            $avgConfidence = count($recentConfidence) > 0 
                ? array_sum($recentConfidence) / count($recentConfidence) 
                : 0;

            $health['details']['avg_confidence'] = $avgConfidence;

            if ($avgConfidence < 50) {
                $health['status'] = 'warning';
                $health['message'] = 'AI confidence below threshold';
            }
        } catch (\Exception $e) {
            $health['status'] = 'critical';
            $health['message'] = 'AI service error';
            $health['details']['error'] = $e->getMessage();
        }

        return $health;
    }

    /**
     * Test AI service
     */
    private function testAIService(): array
    {
        try {
            // Simple test call to AI service
            $testResult = \App\Services\AICapabilitiesService::testConnection();
            return $testResult;
        } catch (\Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Check Channels health
     */
    private function checkChannels(): array
    {
        $health = [
            'status' => 'healthy',
            'message' => 'All channels operational',
            'details' => [],
        ];

        $channels = Channel::all();
        $connectedCount = $channels->where('status', 'connected')->count();
        $totalCount = $channels->count();

        $health['details']['total_channels'] = $totalCount;
        $health['details']['connected_channels'] = $connectedCount;
        $health['details']['disconnected_channels'] = $totalCount - $connectedCount;

        if ($totalCount > 0 && $connectedCount === 0) {
            $health['status'] = 'critical';
            $health['message'] = 'No channels connected';
        } elseif ($connectedCount < $totalCount) {
            $health['status'] = 'warning';
            $health['message'] = 'Some channels disconnected';
        }

        return $health;
    }

    /**
     * Check Queue health
     */
    private function checkQueue(): array
    {
        $health = [
            'status' => 'healthy',
            'message' => 'Queue processing normally',
            'details' => [],
        ];

        try {
            // Check queue size
            $queueSize = Queue::size();
            $health['details']['queue_size'] = $queueSize;

            if ($queueSize > 1000) {
                $health['status'] = 'warning';
                $health['message'] = 'Queue backlog detected';
            }

            if ($queueSize > 5000) {
                $health['status'] = 'critical';
                $health['message'] = 'Queue severely backed up';
            }

            // Check failed jobs
            $failedJobs = Cache::get('failed_jobs_count', 0);
            $health['details']['failed_jobs'] = $failedJobs;

            if ($failedJobs > 100) {
                $health['status'] = 'warning';
                $health['message'] = 'High failed job count';
            }
        } catch (\Exception $e) {
            $health['status'] = 'critical';
            $health['message'] = 'Queue service error';
            $health['details']['error'] = $e->getMessage();
        }

        return $health;
    }

    /**
     * Check Database health
     */
    private function checkDatabase(): array
    {
        $health = [
            'status' => 'healthy',
            'message' => 'Database operational',
            'details' => [],
        ];

        try {
            // Test database connection
            \DB::connection()->getPdo();

            // Check connection count
            $connections = \DB::select('SHOW STATUS LIKE "Threads_connected"');
            $connectionCount = $connections[0]->Value ?? 0;
            
            $health['details']['connections'] = $connectionCount;

            if ($connectionCount > 100) {
                $health['status'] = 'warning';
                $health['message'] = 'High database connection count';
            }
        } catch (\Exception $e) {
            $health['status'] = 'critical';
            $health['message'] = 'Database connection failed';
            $health['details']['error'] = $e->getMessage();
        }

        return $health;
    }

    /**
     * Check Webhook delivery health
     */
    private function checkWebhookDelivery(): array
    {
        $health = [
            'status' => 'healthy',
            'message' => 'Webhook delivery normal',
            'details' => [],
        ];

        try {
            // Check recent webhook failures
            $recentFailures = SystemHealthLog::where('component', 'webhook')
                ->where('status', 'critical')
                ->where('created_at', '>=', now()->subHours(1))
                ->count();

            $health['details']['recent_failures'] = $recentFailures;

            if ($recentFailures > 10) {
                $health['status'] = 'warning';
                $health['message'] = 'High webhook failure rate';
            }

            if ($recentFailures > 50) {
                $health['status'] = 'critical';
                $health['message'] = 'Webhook delivery severely affected';
            }
        } catch (\Exception $e) {
            $health['status'] = 'warning';
            $health['message'] = 'Could not check webhook health';
            $health['details']['error'] = $e->getMessage();
        }

        return $health;
    }

    /**
     * Log health check result
     */
    private function logHealthCheck(array $health): void
    {
        foreach ($health['components'] as $component => $data) {
            if ($data['status'] !== 'healthy') {
                SystemHealthLog::create([
                    'component' => $component,
                    'status' => $data['status'],
                    'message' => $data['message'],
                    'details' => $data['details'],
                ]);
            }
        }

        Log::info('System health check completed', [
            'overall_status' => $health['overall_status'],
            'timestamp' => $health['timestamp'],
        ]);
    }

    /**
     * Send alert if critical issues detected
     */
    public function sendCriticalAlerts(array $health): void
    {
        if ($health['overall_status'] !== 'critical') {
            return;
        }

        $criticalComponents = array_filter($health['components'], function ($component) {
            return $component['status'] === 'critical';
        });

        foreach ($criticalComponents as $component => $data) {
            // Send email to admin
            // This would integrate with your notification service
            Log::critical('System health alert', [
                'component' => $component,
                'message' => $data['message'],
                'details' => $data['details'],
            ]);
        }
    }

    /**
     * Get health history
     */
    public function getHealthHistory(int $hours = 24): array
    {
        $since = now()->subHours($hours);

        $history = SystemHealthLog::where('created_at', '>=', $since)
            ->orderBy('created_at', 'desc')
            ->get()
            ->groupBy('component');

        return $history->toArray();
    }

    /**
     * Check system health (alias for runHealthCheck)
     */
    public function checkSystemHealth(): array
    {
        return $this->runHealthCheck();
    }
}
