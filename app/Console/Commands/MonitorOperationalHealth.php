<?php

namespace App\Console\Commands;

use App\Services\ScheduledFailureNotifier;
use App\Support\OperationalMetricCounter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

class MonitorOperationalHealth extends Command
{
    protected $signature = 'monitor:operations';

    protected $description = 'Alert on queue backlog, webhook signature failures, and outbound channel failures';

    public function handle(OperationalMetricCounter $counters, ScheduledFailureNotifier $notifier): int
    {
        $issues = [];
        $queueThreshold = max(1, (int) config('services.operations.queue_backlog_threshold', 1000));
        $failureThreshold = max(1, min(100, (int) config('services.operations.send_failure_rate_percent', 20)));
        $minimumSample = max(1, (int) config('services.operations.send_failure_min_sample', 10));
        $signatureThreshold = max(1, (int) config('services.operations.webhook_signature_failure_threshold', 5));

        try {
            $queueSize = (int) Queue::size();
            if ($queueSize >= $queueThreshold) {
                $issues[] = [
                    'key' => 'queue_backlog',
                    'summary' => "Queue backlog {$queueSize} reached threshold {$queueThreshold}.",
                    'context' => ['queue_backlog' => $queueSize, 'threshold' => $queueThreshold],
                ];
            }
        } catch (\Throwable $exception) {
            Log::warning('Operational monitor could not read queue backlog', ['exception' => get_class($exception)]);
        }

        $signatureFailures = $counters->currentWindowCount('webhook_signature_failures');
        if ($signatureFailures >= $signatureThreshold) {
            $issues[] = [
                'key' => 'webhook_signature_failures',
                'summary' => "Webhook signature failures in the current five-minute window: {$signatureFailures}.",
                'context' => ['webhook_signature_failures' => $signatureFailures, 'threshold' => $signatureThreshold],
            ];
        }

        try {
            $channelFailures = DB::table('messages')
                ->join('conversations', 'messages.conversation_id', '=', 'conversations.id')
                ->join('channels', 'conversations.channel_id', '=', 'channels.id')
                ->where('messages.direction', 'outbound')
                ->whereIn('messages.send_status', ['sent', 'failed'])
                ->where('messages.created_at', '>=', now()->subMinutes(15))
                ->groupBy('channels.id', 'channels.type')
                ->select('channels.id as channel_id', 'channels.type as channel_type')
                ->selectRaw('COUNT(*) as total')
                ->selectRaw("SUM(CASE WHEN messages.send_status = 'failed' THEN 1 ELSE 0 END) as failures")
                ->get();

            foreach ($channelFailures as $channel) {
                $total = (int) $channel->total;
                $failures = (int) $channel->failures;
                $rate = $total > 0 ? ($failures / $total) * 100 : 0;
                if ($total >= $minimumSample && $rate >= $failureThreshold) {
                    $issues[] = [
                        'key' => 'send_failure_channel_' . $channel->channel_id,
                        'summary' => sprintf(
                            'Outbound failure rate for channel %d (%s) is %.1f%% over the last 15 minutes.',
                            $channel->channel_id,
                            $channel->channel_type,
                            $rate
                        ),
                        'context' => [
                            'channel_id' => (int) $channel->channel_id,
                            'channel_type' => (string) $channel->channel_type,
                            'total' => $total,
                            'failures' => $failures,
                            'rate_percent' => round($rate, 1),
                            'threshold_percent' => $failureThreshold,
                        ],
                    ];
                }
            }
        } catch (\Throwable $exception) {
            Log::warning('Operational monitor could not calculate outbound failure rates', [
                'exception' => get_class($exception),
            ]);
        }

        foreach ($issues as $issue) {
            Log::alert('Operational monitoring threshold reached', $issue['context'] + ['metric' => $issue['key']]);
            $dedupeKey = 'ops:alerted:' . sha1($issue['key']);
            if (Cache::add($dedupeKey, true, now()->addMinutes(max(1, (int) config('services.operations.alert_dedup_minutes', 60))))) {
                $notifier->notify($issue['summary']);
            }
        }

        $this->info(sprintf('Operational monitor complete; %d alert condition(s) active.', count($issues)));
        return Command::SUCCESS;
    }
}
