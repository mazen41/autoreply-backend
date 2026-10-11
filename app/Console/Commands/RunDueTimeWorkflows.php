<?php

namespace App\Console\Commands;

use App\Models\BusinessProfile;
use App\Models\Conversation;
use App\Services\AutomationEngine;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class RunDueTimeWorkflows extends Command
{
    protected $signature = 'workflows:run-time-triggers';
    protected $description = 'Run scheduled workflow triggers in each business timezone';

    public function handle(AutomationEngine $engine): int
    {
        $businessCount = 0;
        $conversationCount = 0;
        $nowUtc = now()->utc();

        BusinessProfile::query()->whereHas('workflows', function ($query): void {
            $query->active()->where('trigger_config->type', 'time');
        })->orderBy('id')->chunkById(100, function ($businesses) use (
            $engine,
            $nowUtc,
            &$businessCount,
            &$conversationCount
        ): void {
            foreach ($businesses as $business) {
                $timezone = $business->timezone ?: 'UTC';
                try {
                    $localNow = $nowUtc->copy()->setTimezone($timezone);
                } catch (\Throwable $exception) {
                    Log::warning('RunDueTimeWorkflows: invalid business timezone; using UTC', [
                        'business_id' => $business->id,
                        'timezone' => $timezone,
                    ]);
                    $localNow = $nowUtc->copy()->setTimezone('UTC');
                }
                Conversation::query()
                    ->where('business_id', $business->id)
                    ->where('status', 'open')
                    ->orderBy('id')
                    ->chunkById(200, function ($conversations) use ($engine, $localNow, $business, &$conversationCount): void {
                        foreach ($conversations as $conversation) {
                            $engine->executeWorkflowsForEvent(
                                AutomationEngine::EVENT_TIME_TRIGGER,
                                $conversation,
                                [
                                    'event_id' => 'scheduled:' . $localNow->format('Y-m-d'),
                                    'scheduled_at' => now()->utc()->toIso8601String(),
                                    'business_id' => $business->id,
                                ]
                            );
                            $conversationCount++;
                        }
                    });

                $businessCount++;
            }
        });

        $this->info("Checked {$businessCount} business timezone(s) across {$conversationCount} conversation(s).");
        return self::SUCCESS;
    }
}
