<?php

namespace App\Console\Commands;

use App\Models\Campaign;
use App\Services\CampaignLaunchService;
use Illuminate\Console\Command;

class SendDueCampaigns extends Command
{
    protected $signature = 'campaigns:send-due';
    protected $description = 'Launch scheduled messaging campaigns whose scheduled_at time has passed';

    public function handle(): int
    {
        $campaigns = Campaign::where('status', 'scheduled')
            ->where('scheduled_at', '<=', now())
            ->get();

        $campaignLaunchService = app(CampaignLaunchService::class);

        foreach ($campaigns as $campaign) {
            $data = $campaignLaunchService->launch($campaign->business_id, $campaign->id, ['scheduled']);

            if (!empty($data['success'])) {
                $this->info("Queued campaign #{$campaign->id}");
            } else {
                // Not an error worth failing the whole cron run over — most
                // commonly this means another process (a concurrent cron
                // run, or a manual "Launch Now") already claimed the
                // campaign first, which is the atomic claim guard in
                // CampaignLaunchService working as intended.
                $this->info("Skipped campaign #{$campaign->id}: " . ($data['error'] ?? 'not launchable'));
            }
        }

        return self::SUCCESS;
    }
}
