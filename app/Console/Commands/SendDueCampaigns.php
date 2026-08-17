<?php

namespace App\Console\Commands;

use App\Http\Controllers\Api\CampaignController;
use App\Models\Campaign;
use Illuminate\Console\Command;
use Illuminate\Http\Request;

class SendDueCampaigns extends Command
{
    protected $signature = 'campaigns:send-due';
    protected $description = 'Launch scheduled messaging campaigns whose scheduled_at time has passed';

    public function handle(): int
    {
        $campaigns = Campaign::where('status', 'scheduled')
            ->where('scheduled_at', '<=', now())
            ->get();

        $controller = app(CampaignController::class);

        foreach ($campaigns as $campaign) {
            $response = $controller->launchScheduled(new Request(), $campaign->business_id, $campaign->id);
            $data = json_decode($response->getContent(), true);

            if (!empty($data['success'])) {
                $this->info("Queued campaign #{$campaign->id}");
            } else {
                // Not an error worth failing the whole cron run over — most
                // commonly this means another process (a concurrent cron
                // run, or a manual "Launch Now") already claimed the
                // campaign first, which is the race-condition guard in
                // CampaignController::launchCampaign working as intended.
                $this->info("Skipped campaign #{$campaign->id}: " . ($data['error'] ?? 'not launchable'));
            }
        }

        return self::SUCCESS;
    }
}
