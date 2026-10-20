<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Channel;
use App\Models\BusinessProfile;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ReportsController extends Controller
{
    /**
     * Base query: every message belonging to a conversation whose channel
     * belongs to the current user. The messages table has no user_id column
     * of its own, so this join is required for every report below.
     */
    private function businessId(): int
    {
        $request = request();
        $business = $request->attributes->get('active_business');
        if (!$business) {
            $business = app(\App\Services\ActiveBusiness::class)->resolve($request, permission: 'read');
            $request->attributes->set('active_business', $business);
        }

        return (int) $business->id;
    }

    private function userMessages(array $filters = [])
    {
        $businessId = $this->businessId();
        return Message::whereHas('conversation', function ($query) use ($businessId, $filters): void {
            $query->where('business_id', $businessId)
                ->when(!empty($filters['channel_id']), fn ($conversation) => $conversation->where('channel_id', $filters['channel_id']))
                ->when(!empty($filters['bot_id']), fn ($conversation) => $conversation->where('bot_id', $filters['bot_id']));
        });
    }

    private function dashboardRange(Request $request, int $defaultDays = 7): array
    {
        $validated = $request->validate([
            'days' => 'sometimes|integer|min:1|max:365',
            'timezone' => 'sometimes|string|max:64',
            'channel_id' => ['sometimes', 'nullable', 'integer', Rule::exists('channels', 'id')->where('business_id', $this->businessId())],
            'bot_id' => ['sometimes', 'nullable', 'integer', Rule::exists('bots', 'id')->where('business_profile_id', $this->businessId())],
        ]);
        $days = (int) ($validated['days'] ?? $defaultDays);
        $business = $request->attributes->get('active_business')
            ?? BusinessProfile::find($this->businessId());
        $businessTimezone = $business?->timezone;
        $timezone = $businessTimezone && in_array($businessTimezone, timezone_identifiers_list(), true)
            ? $businessTimezone
            : (($validated['timezone'] ?? null) && in_array($validated['timezone'], timezone_identifiers_list(), true)
                ? $validated['timezone']
                : 'UTC');

        $nowLocal = CarbonImmutable::now($timezone);
        $startLocal = $nowLocal->startOfDay()->subDays($days - 1);
        $startUtc = $startLocal->setTimezone('UTC');
        $endUtc = $nowLocal->setTimezone('UTC');
        $previousStartUtc = $startUtc->subDays($days);
        $previousEndUtc = $startUtc->subMicrosecond();

        return [
            'days' => $days,
            'timezone' => $timezone,
            'start_utc' => $startUtc,
            'end_utc' => $endUtc,
            'previous_start_utc' => $previousStartUtc,
            'previous_end_utc' => $previousEndUtc,
            'period_start' => $startLocal->toDateString(),
            'period_end' => $nowLocal->toDateString(),
            'channel_id' => isset($validated['channel_id']) ? (int) $validated['channel_id'] : null,
            'bot_id' => isset($validated['bot_id']) ? (int) $validated['bot_id'] : null,
        ];
    }

    private function messagesForRange(array $range)
    {
        return $this->userMessages($range)->whereBetween('created_at', [$range['start_utc'], $range['end_utc']]);
    }

    private function rangeResponseFields(array $range): array
    {
        return [
            'range_days' => $range['days'],
            'timezone' => $range['timezone'],
            'period_start' => $range['period_start'],
            'period_end' => $range['period_end'],
        ];
    }

    /**
     * Get daily message counts for the current user
     */
    public function dailyMessages(Request $request)
    {
        $range = $this->dashboardRange($request, 30);
        $counts = $this->messagesForRange($range)->get(['created_at'])
            ->groupBy(fn ($message) => $message->created_at->setTimezone($range['timezone'])->toDateString())
            ->map(fn ($messages) => $messages->count());
        $result = [];
        $dates = [];
        for ($i = 0; $i < $range['days']; $i++) {
            $date = CarbonImmutable::parse($range['period_start'], $range['timezone'])->addDays($i)->toDateString();
            $dates[] = $date;
            $result[] = (int) ($counts[$date] ?? 0);
        }

        return response()->json([
            'data' => $result,
            'dates' => $dates,
            'total' => array_sum($result),
        ] + $this->rangeResponseFields($range));
    }

    /**
     * Get message breakdown by channel
     */
    public function channelBreakdown(Request $request)
    {
        $channels = Channel::where('business_id', $this->businessId())
            ->withCount('messages as messages_count')
            ->get()
            ->map(function ($channel) {
                return [
                    'id' => $channel->id,
                    'type' => $channel->type,
                    'name' => $channel->page_name ?? $channel->type,
                    'messages_count' => $channel->messages_count,
                ];
            });

        return response()->json([
            'channels' => $channels,
            'total' => $channels->sum('messages_count'),
        ]);
    }

    /**
     * Compute average response time in seconds: for every inbound message,
     * find the next outbound message in the same conversation and measure
     * the gap between them. There's no stored response_time column, so this
     * is calculated on the fly from real timestamps.
     */
    private function averageResponseTimeSeconds(array $range): float
    {
        return app(\App\Services\AnalyticsService::class)->medianResponseTimeSeconds(
            $this->businessId(),
            $range['start_utc'],
            $range['end_utc'],
            $range['channel_id'] ?? null,
            $range['bot_id'] ?? null,
        ) ?? 0.0;
    }

    /**
     * Get AI performance metrics
     */
    public function aiPerformance(Request $request)
    {
        $range = $this->dashboardRange($request, 30);
        $automatedSources = ['campaign', 'email_campaign', 'sequence', 'automation', 'proactive'];
        $customerReplyMessages = fn () => $this->messagesForRange($range)
            ->where('direction', 'outbound')
            ->where(fn ($query) => $query->whereNull('source')->orWhereNotIn('source', $automatedSources));
        $totalReplies = $customerReplyMessages()->count();
        $autoReplies = $this->messagesForRange($range)
            ->where('direction', 'outbound')
            ->where('is_ai', true)
            ->where(fn ($query) => $query->whereNull('source')->orWhereNotIn('source', $automatedSources))
            ->count();

        $manualInterventions = $this->messagesForRange($range)
            ->where('is_ai', false)
            ->where('direction', 'outbound')
            ->where(fn ($query) => $query->whereNull('source')->orWhereNotIn('source', $automatedSources))
            ->count();

        $avgResponseTime = $this->averageResponseTimeSeconds($range);
        $shareOfReplies = $totalReplies > 0 ? ($autoReplies / $totalReplies) * 100 : 0;
        $aiConversations = Conversation::where('business_id', $this->businessId())
            ->when(!empty($range['channel_id']), fn ($query) => $query->where('channel_id', $range['channel_id']))
            ->when(!empty($range['bot_id']), fn ($query) => $query->where('bot_id', $range['bot_id']))
            ->whereHas('messages', fn ($query) => $query->where('direction', 'outbound')->where('is_ai', true)
                ->where(fn ($source) => $source->whereNull('source')->orWhereNotIn('source', $automatedSources))
                ->whereBetween('created_at', [$range['start_utc'], $range['end_utc']]))
            ->count();

        return response()->json([
            'total_messages' => $this->messagesForRange($range)->count(), // deprecated compatibility field
            'total_replies' => $totalReplies,
            'auto_replies' => $autoReplies,
            'ai_share_of_replies' => round($shareOfReplies, 1),
            'auto_reply_rate' => round($shareOfReplies, 1), // deprecated compatibility alias
            'manual_interventions' => $manualInterventions,
            'ai_conversations' => $aiConversations,
            'median_response_time_seconds' => round($avgResponseTime, 1),
            'median_response_time_formatted' => $avgResponseTime < 60
                ? round($avgResponseTime, 1) . 's'
                : round($avgResponseTime / 60, 1) . 'm',
            'avg_response_time_seconds' => round($avgResponseTime, 1), // deprecated compatibility alias
            'avg_response_time_formatted' => $avgResponseTime < 60
                ? round($avgResponseTime, 1) . 's'
                : round($avgResponseTime / 60, 1) . 'm',
        ] + $this->rangeResponseFields($range));
    }

    /**
     * Get top questions from conversations using improved detection
     */
    public function topQuestions(Request $request)
    {
        $validated = $request->validate([
            'limit' => 'sometimes|integer|min:1|max:100',
            'days' => 'sometimes|integer|min:1|max:365',
            'timezone' => 'sometimes|string|max:64',
        ]);
        $limit = (int) ($validated['limit'] ?? 10);
        $range = $this->dashboardRange($request, 30);

        $messages = $this->messagesForRange($range)
            ->where('direction', 'inbound')
            ->select('content')
            ->get()
            ->pluck('content')
            ->toArray();

        // Analyze messages to extract questions using multiple patterns
        $questions = [];
        
        foreach ($messages as $message) {
            $questionPatterns = [
                // Standard question marks (English + Arabic question mark)
                '/\?|؟/u',
                // Question words at start (English)
                '/^(what|where|when|why|how|who|which|whose|is|are|do|does|did|can|could|would|should|will)\b/iu',
                // Question words at start (Arabic)
                '/^(ما|متى|أين|كيف|لماذا|من|كم|هل)(?:\s|$)/u',
            ];

            $isQuestion = false;
            foreach ($questionPatterns as $pattern) {
                if (preg_match($pattern, trim($message))) {
                    $isQuestion = true;
                    break;
                }
            }

            if ($isQuestion) {
                // Normalize the question for grouping
                $normalized = $this->normalizeQuestion($message);
                $questions[$normalized] = ($questions[$normalized] ?? 0) + 1;
            }
        }

        // Sort by frequency and limit results
        arsort($questions);
        $questions = array_filter($questions, fn ($count) => $count >= 2);
        $topQuestions = array_slice($questions, 0, $limit, true);

        // Format for response
        $formattedQuestions = collect($topQuestions)->map(function($count, $question) {
            return [
                'question' => $question,
                'count' => $count,
            ];
        })->values();

        return response()->json([
            'questions' => $formattedQuestions,
        ]);
    }

    /**
     * Normalize questions for grouping by removing common variations
     */
    private function normalizeQuestion(string $question): string
    {
        // Convert to lowercase for English, keep Arabic as is
        $normalized = mb_strtolower(trim($question));
        
        // Remove extra whitespace
        $normalized = preg_replace('/\s+/', ' ', $normalized);
        
        // Remove common filler words
        $fillerWords = ['please', 'plz', 'thanks', 'thank you', 'شكرا', 'شكراً'];
        foreach ($fillerWords as $word) {
            $normalized = str_ireplace($word, '', $normalized);
        }
        
        // Remove trailing punctuation
        $normalized = rtrim($normalized, '!?؟.،');
        
        // Normalize whitespace again
        $normalized = preg_replace('/\s+/', ' ', trim($normalized));
        
        return $normalized;
    }

    /**
     * Get time saved metrics
     */
    public function timeSaved(Request $request)
    {
        $range = $this->dashboardRange($request, 30);
        $business = BusinessProfile::findOrFail($this->businessId());
        $autoReplies = $this->messagesForRange($range)
            ->where('is_ai', true)
            ->where('direction', 'outbound')
            ->where(fn ($query) => $query->whereNull('source')->orWhereNotIn('source', ['campaign', 'email_campaign', 'sequence', 'automation', 'proactive']))
            ->count();

        // Use configurable values or defaults
        $manualReplyTimeMinutes = $business->estimated_manual_reply_minutes
            ?? config('services.metrics.avg_manual_reply_time', 3);
        $totalTimeSavedMinutes = $autoReplies * $manualReplyTimeMinutes;
        $totalTimeSavedHoursRaw = $totalTimeSavedMinutes / 60;
        $totalTimeSavedHours = round($totalTimeSavedHoursRaw, 1);

        // Estimate value using configurable hourly rate
        $hourlyRate = $business->estimated_hourly_rate
            ?? config('services.metrics.hourly_rate', 100);
        $estimatedValue = round($totalTimeSavedHoursRaw * $hourlyRate);
        $currency = $business->estimated_hourly_rate_currency ?: 'SAR';

        return response()->json([
            'messages_handled' => $autoReplies,
            'avg_manual_reply_time' => $manualReplyTimeMinutes . ' ' . ($request->get('lang') === 'ar' ? 'دقائق' : 'min'),
            'time_saved_hours' => $totalTimeSavedHours,
            'estimated_value' => $estimatedValue,
            'estimated_value_formatted' => $estimatedValue . ' ' . $currency,
            'estimate_inputs' => [
                'minutes_per_reply' => $manualReplyTimeMinutes,
                'hourly_rate' => $hourlyRate,
                'hourly_rate_currency' => $business->estimated_hourly_rate_currency ?: 'SAR',
                'is_estimate' => true,
            ],
        ] + $this->rangeResponseFields($range));
    }

    public function updateTimeSavedSettings(Request $request)
    {
        $validated = $request->validate([
            'minutes_per_reply' => 'required|integer|min:1|max:60',
            'hourly_rate' => 'required|numeric|min:0|max:1000000',
            'currency' => ['required', 'string', 'regex:/^[A-Z]{3}$/'],
        ]);
        $business = app(\App\Services\ActiveBusiness::class)->resolve($request, permission: 'write');
        $business->forceFill([
            'estimated_manual_reply_minutes' => $validated['minutes_per_reply'],
            'estimated_hourly_rate' => $validated['hourly_rate'],
            'estimated_hourly_rate_currency' => $validated['currency'],
        ])->save();

        return response()->json([
            'minutes_per_reply' => $business->estimated_manual_reply_minutes,
            'hourly_rate' => (float) $business->estimated_hourly_rate,
            'currency' => $business->estimated_hourly_rate_currency,
        ]);
    }

    /**
     * Get comprehensive report summary
     */
    public function summary(Request $request)
    {
        return response()->json([
            'daily_messages' => $this->dailyMessages($request)->getData(true),
            'channel_breakdown' => $this->channelBreakdown($request)->getData(true),
            'ai_performance' => $this->aiPerformance($request)->getData(true),
            'top_questions' => $this->topQuestions($request)->getData(true),
            'time_saved' => $this->timeSaved($request)->getData(true),
        ]);
    }

    /**
     * Get top-level dashboard stats (used by the main dashboard home page)
     */
    public function dashboardStats(Request $request)
    {
        $range = $this->dashboardRange($request);
        $totalMessages = $this->messagesForRange($range)->count();
        $automatedSources = ['campaign', 'email_campaign', 'sequence', 'automation', 'proactive'];
        $aiReplies = $this->messagesForRange($range)
            ->where('direction', 'outbound')
            ->where('is_ai', true)
            ->where(fn ($query) => $query->whereNull('source')->orWhereNotIn('source', $automatedSources))
            ->count();
        $totalReplies = $this->messagesForRange($range)
            ->where('direction', 'outbound')
            ->where(fn ($query) => $query->whereNull('source')->orWhereNotIn('source', $automatedSources))
            ->count();
        $responseRate = $totalReplies > 0 ? round(($aiReplies / $totalReplies) * 100, 1) : 0;

        // Time saved: use configurable reply time
        $replyTimeMinutes = config('services.metrics.avg_manual_reply_time', 3);
        $hoursSaved = round(($aiReplies * $replyTimeMinutes) / 60, 1);

        $thisWeek = $this->messagesForRange($range)->count();
        $lastWeek = $this->userMessages($range)->whereBetween('created_at', [
            $range['previous_start_utc'], $range['previous_end_utc'],
        ])->count();
        $messagesTrend = null;
        if ($lastWeek > 0) {
            $change = round((($thisWeek - $lastWeek) / $lastWeek) * 100, 1);
            $messagesTrend = ['value' => abs($change), 'isPositive' => $change >= 0];
        }

        $topQuestionRow = $this->topQuestions($request)->getData(true)['questions'][0] ?? null;

        return response()->json([
            'total_messages' => $totalMessages,
            'ai_replies' => $aiReplies,
            'total_replies' => $totalReplies,
            'ai_share_of_replies' => $responseRate,
            'response_rate' => $responseRate,
            'hours_saved' => $hoursSaved,
            'messages_trend' => $messagesTrend,
            'top_question' => $topQuestionRow['question'] ?? null,
            'question_count' => $topQuestionRow['count'] ?? 0,
        ] + $this->rangeResponseFields($range));
    }

    /**
     * Export reports as CSV
     */
    public function exportCsv(Request $request)
    {
        $validated = $request->validate([
            'type' => ['sometimes', 'in:messages,channels,ai_performance'],
            'days' => ['sometimes', 'integer', 'min:1', 'max:365'],
        ]);
        $type = $validated['type'] ?? 'messages';
        $range = $this->dashboardRange($request, 30);

        $filename = "report_{$type}_" . now()->format('Y-m-d') . '.csv';
        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ];

        $callback = function () use ($type, $range, $request) {
            $file = fopen('php://output', 'w');
            $this->writeCsvRow($file, ['Period', $range['period_start'], $range['period_end'], 'Timezone', $range['timezone']]);

            if ($type === 'messages') {
                $this->writeCsvRow($file, ['Date', 'Direction', 'Is AI', 'Content', 'Channel Type']);
                
                $this->messagesForRange($range)
                    ->with('conversation.channel')
                    ->orderBy('created_at', 'desc')
                    ->chunk(1000, function ($messages) use ($file) {
                        foreach ($messages as $msg) {
                            $this->writeCsvRow($file, [
                                $msg->created_at,
                                $msg->direction,
                                $msg->is_ai ? 'Yes' : 'No',
                                mb_substr((string) $msg->content, 0, 500, 'UTF-8'),
                                $msg->conversation->channel->type ?? 'Unknown',
                            ]);
                        }
                    });
            } elseif ($type === 'channels') {
                $this->writeCsvRow($file, ['Channel Type', 'Channel Name', 'Message Count', 'Status']);
                
                Channel::where('business_id', $this->businessId())
                    ->withCount(['messages as messages_count' => fn ($query) => $query->whereBetween('messages.created_at', [$range['start_utc'], $range['end_utc']])])
                    ->get()
                    ->each(function ($channel) use ($file) {
                        $this->writeCsvRow($file, [
                            $channel->type,
                            $channel->page_name ?? 'N/A',
                            $channel->messages_count,
                            $channel->status,
                        ]);
                    });
            } elseif ($type === 'ai_performance') {
                $this->writeCsvRow($file, ['Metric', 'Value']);
                $metrics = $this->aiPerformance($request)->getData(true);
                $this->writeCsvRow($file, ['AI Share of Replies (%)', $metrics['ai_share_of_replies']]);
                $this->writeCsvRow($file, ['Manual Interventions', $metrics['manual_interventions']]);
                $this->writeCsvRow($file, ['AI Conversations', $metrics['ai_conversations']]);
                $this->writeCsvRow($file, ['Median Response Time (seconds)', $metrics['median_response_time_seconds']]);
            }

            fclose($file);
        };

        return response()->stream($callback, 200, $headers);
    }

    private function writeCsvRow($file, array $values): void
    {
        $safe = array_map(function ($value): string {
            $cell = (string) ($value ?? '');
            return preg_match('/^[=+\-@\t\r]/', $cell) ? "'" . $cell : $cell;
        }, $values);
        fputcsv($file, $safe);
    }

    /** Export a business-scoped, Unicode PDF report for the selected local date range. */
    public function exportPdf(Request $request)
    {
        $validated = $request->validate([
            'type' => ['sometimes', 'in:messages,channels,ai_performance'],
            'days' => ['sometimes', 'integer', 'min:1', 'max:365'],
        ]);
        $type = $validated['type'] ?? 'messages';
        $range = $this->dashboardRange($request, 30);
        $periodLabel = $range['period_start'] . ' – ' . $range['period_end'] . ' (' . $range['timezone'] . ')';
        $headers = [];
        $rows = [];
        $rowLimit = 100;
        $totalRows = 0;

        if ($type === 'messages') {
            $headers = ['Date', 'Direction', 'AI', 'Channel', 'Content'];
            $query = $this->messagesForRange($range)->with('conversation.channel')->orderByDesc('created_at');
            $totalRows = (clone $query)->count();
            $rows = $query->limit($rowLimit)->get()->map(fn (Message $message) => [
                $message->created_at->setTimezone($range['timezone'])->format('Y-m-d H:i'),
                $message->direction,
                $message->is_ai ? 'Yes' : 'No',
                $message->conversation?->channel?->type ?? 'Unknown',
                mb_substr((string) $message->content, 0, 300, 'UTF-8'),
            ])->all();
        } elseif ($type === 'channels') {
            $headers = ['Channel', 'Name', 'Messages in range', 'Status'];
            $channels = Channel::where('business_id', $this->businessId())
                ->withCount(['messages as messages_count' => fn ($query) => $query->whereBetween('messages.created_at', [$range['start_utc'], $range['end_utc']])])
                ->orderBy('type')
                ->limit($rowLimit)
                ->get();
            $totalRows = Channel::where('business_id', $this->businessId())->count();
            $rows = $channels->map(fn (Channel $channel) => [
                $channel->type,
                $channel->page_name ?: $channel->store_name ?: $channel->page_id ?: 'N/A',
                (string) $channel->messages_count,
                $channel->status,
            ])->all();
        } else {
            $headers = ['Metric', 'Value'];
            $metrics = $this->aiPerformance($request)->getData(true);
            $rows = [
                ['AI share of replies (%)', (string) round((float) ($metrics['ai_share_of_replies'] ?? 0), 1)],
                ['Manual interventions', (string) ($metrics['manual_interventions'] ?? 0)],
                ['AI conversations', (string) ($metrics['ai_conversations'] ?? 0)],
                ['Median response time (seconds)', (string) round((float) ($metrics['median_response_time_seconds'] ?? 0), 1)],
            ];
            $totalRows = count($rows);
        }

        $escape = static fn ($value): string => htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $cell = static function ($value) use ($escape): string {
            $text = (string) ($value ?? '');
            $rtl = preg_match('/[\x{0600}-\x{06FF}]/u', $text) === 1;
            return '<td dir="' . ($rtl ? 'rtl' : 'ltr') . '"' . ($rtl ? ' lang="ar"' : '') . '>' . $escape($text) . '</td>';
        };
        $tableHead = '<tr>' . implode('', array_map(fn ($header) => '<th>' . $escape($header) . '</th>', $headers)) . '</tr>';
        $tableRows = '';
        foreach ($rows as $row) {
            $tableRows .= '<tr>' . implode('', array_map($cell, $row)) . '</tr>';
        }
        $notice = $totalRows > $rowLimit
            ? '<p class="notice">Showing the first ' . $rowLimit . ' of ' . $totalRows . ' rows.</p>'
            : '<p class="notice">Showing ' . count($rows) . ' rows.</p>';
        $html = '<!doctype html><html lang="en"><head><meta charset="utf-8"><style>
            body{font-family:dejavusans,sans-serif;font-size:9pt;color:#18212f}h1{font-size:18pt;margin:0 0 5mm}p.meta{color:#526071;font-size:8pt}table{border-collapse:collapse;width:100%;margin-top:6mm}th,td{border:1px solid #cbd5e1;padding:5px;vertical-align:top}th{background:#eef2f7;font-weight:bold}.notice{margin-top:4mm;font-size:8pt;color:#526071}td[dir="rtl"]{text-align:right}
            </style></head><body dir="ltr"><h1>' . $escape(ucfirst(str_replace('_', ' ', $type))) . ' Report</h1><p class="meta">' . $escape($periodLabel) . ' · Generated ' . $escape(now()->toDateTimeString()) . '</p><table><thead>' . $tableHead . '</thead><tbody>' . $tableRows . '</tbody></table>' . $notice . '</body></html>';

        $tempDir = storage_path('app/mpdf-temp');
        if (!is_dir($tempDir)) {
            mkdir($tempDir, 0770, true);
        }
        $pdf = new \Mpdf\Mpdf([
            'mode' => 'utf-8',
            'default_font' => 'dejavusans',
            'tempDir' => $tempDir,
        ]);
        $pdf->WriteHTML($html);
        $filename = "report_{$type}_" . now()->format('Y-m-d') . '.pdf';

        return response()->streamDownload(
            fn () => print($pdf->Output('', 'S')),
            $filename,
            ['Content-Type' => 'application/pdf']
        );
    }

}
