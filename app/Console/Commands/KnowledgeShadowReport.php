<?php

namespace App\Console\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use SplFileObject;

class KnowledgeShadowReport extends Command
{
    protected $signature = 'knowledge:shadow-report {--days=7 : Number of recent days to include}';

    protected $description = 'Summarize knowledge relevance shadow samples by business and score bucket.';

    public function handle(): int
    {
        $days = filter_var($this->option('days'), FILTER_VALIDATE_INT);
        if (!$days || $days < 1 || $days > 365) {
            $this->error('--days must be an integer between 1 and 365.');
            return self::FAILURE;
        }

        $cutoff = CarbonImmutable::now()->subDays($days);
        $businesses = [];
        $files = glob(storage_path('logs/laravel*.log')) ?: [];

        foreach ($files as $path) {
            $file = new SplFileObject($path, 'r');
            while (!$file->eof()) {
                $line = $file->fgets();
                if (!str_contains($line, 'Knowledge relevance shadow sample')) {
                    continue;
                }

                if (!preg_match('/^\[([^\]]+)\].*Knowledge relevance shadow sample\s+(\{.*\})\s*$/', trim($line), $matches)) {
                    continue;
                }

                try {
                    if (CarbonImmutable::parse($matches[1])->lt($cutoff)) {
                        continue;
                    }
                } catch (\Throwable) {
                    continue;
                }

                $sample = json_decode($matches[2], true);
                $businessId = (int) ($sample['business_id'] ?? 0);
                $bucket = $sample['top_score_bucket'] ?? null;
                if ($businessId < 1 || !in_array($bucket, ['none', 'below_055', 'from_055_to_064', 'from_065_to_074', 'at_least_075'], true)) {
                    continue;
                }

                $businesses[$businessId] ??= [];
                $businesses[$businessId][$bucket] ??= [
                    'samples' => 0,
                    'top_chunk_count' => 0,
                    'would_filter_below_055' => 0,
                    'would_filter_below_065' => 0,
                    'would_filter_below_075' => 0,
                ];

                foreach (array_keys($businesses[$businessId][$bucket]) as $field) {
                    $businesses[$businessId][$bucket][$field] += (int) ($sample[$field] ?? 0);
                }
                $businesses[$businessId][$bucket]['samples']++;
            }
        }

        if ($businesses === []) {
            $this->info("No relevance shadow samples found in the last {$days} days.");
            return self::SUCCESS;
        }

        $rows = [];
        ksort($businesses);
        foreach ($businesses as $businessId => $buckets) {
            ksort($buckets);
            foreach ($buckets as $bucket => $counts) {
                $rows[] = [$businessId, $bucket, ...array_values($counts)];
            }
        }

        $this->table([
            'Business ID', 'Top score bucket', 'Samples', 'Top chunks',
            'Would filter <0.55', 'Would filter <0.65', 'Would filter <0.75',
        ], $rows);

        return self::SUCCESS;
    }
}
