<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\BusinessProfile;
use App\Models\Subscription;
use Carbon\Carbon;

class CheckUsageLimits extends Command
{
    protected $signature = 'usage:check';
    protected $description = 'Check and enforce usage limits for all businesses';

    public function __construct()
    {
        parent::__construct();
    }

    public function handle()
    {
        $this->info('Checking usage limits...');

        $businesses = BusinessProfile::with('subscription')->get();

        foreach ($businesses as $business) {
            try {
                $subscription = $business->subscription;
                
                if (!$subscription || $subscription->status !== 'active') {
                    continue;
                }

                // Get current month's usage
                $monthStart = Carbon::now()->startOfMonth();
                $monthEnd = Carbon::now()->endOfMonth();

                $messageCount = $business->conversations()
                    ->whereBetween('created_at', [$monthStart, $monthEnd])
                    ->count();

                $aiReplyCount = $business->conversations()
                    ->where('ai_replied', true)
                    ->whereBetween('created_at', [$monthStart, $monthEnd])
                    ->count();

                // Check against limits
                $planLimits = $this->getPlanLimits($subscription->plan);
                
                if ($aiReplyCount >= $planLimits['ai_replies']) {
                    $this->warn("Business ID {$business->id} has reached AI reply limit ({$aiReplyCount}/{$planLimits['ai_replies']})");
                    // You could send notification or temporarily disable AI
                }

                if ($messageCount >= $planLimits['total_messages']) {
                    $this->warn("Business ID {$business->id} has reached message limit ({$messageCount}/{$planLimits['total_messages']})");
                }

                $this->info("Business ID {$business->id}: {$aiReplyCount}/{$planLimits['ai_replies']} AI replies, {$messageCount}/{$planLimits['total_messages']} total messages");

            } catch (\Exception $e) {
                $this->error("Failed to check usage for business ID {$business->id}: {$e->getMessage()}");
            }
        }

        $this->info('Usage limits check completed.');
        return Command::SUCCESS;
    }

    private function getPlanLimits(string $plan): array
    {
        $limits = [
            'starter' => ['ai_replies' => 500, 'total_messages' => 1000],
            'business' => ['ai_replies' => 2000, 'total_messages' => 5000],
            'growth' => ['ai_replies' => 5000, 'total_messages' => 10000],
            'agency' => ['ai_replies' => 999999, 'total_messages' => 999999],
        ];

        return $limits[$plan] ?? $limits['starter'];
    }
}