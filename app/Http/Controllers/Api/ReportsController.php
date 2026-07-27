<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Channel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class ReportsController extends Controller
{
    /**
     * Base query: every message belonging to a conversation whose channel
     * belongs to the current user. The messages table has no user_id column
     * of its own, so this join is required for every report below.
     */
    private function userMessages()
    {
        $user = Auth::user();
        return Message::whereHas('conversation.channel', function ($q) use ($user) {
            $q->where('user_id', $user->id);
        });
    }

    /**
     * Get daily message counts for the current user
     */
    public function dailyMessages(Request $request)
    {
        $days = $request->get('days', 30); // Default to last 30 days

        $data = $this->userMessages()
            ->where('created_at', '>=', now()->subDays($days))
            ->select(DB::raw('DATE(created_at) as date'), DB::raw('COUNT(*) as count'))
            ->groupBy('date')
            ->orderBy('date')
            ->get()
            ->pluck('count', 'date')
            ->toArray();

        // Fill in missing days with 0
        $result = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $date = now()->subDays($i)->format('Y-m-d');
            $result[] = $data[$date] ?? 0;
        }

        return response()->json([
            'data' => $result,
            'total' => array_sum($result),
        ]);
    }

    /**
     * Get message breakdown by channel
     */
    public function channelBreakdown(Request $request)
    {
        $user = Auth::user();

        $channels = Channel::where('user_id', $user->id)
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
    private function averageResponseTimeSeconds(): float
    {
        $user = Auth::user();

        $conversationIds = Conversation::whereHas('channel', function ($q) use ($user) {
            $q->where('user_id', $user->id);
        })->pluck('id');

        if ($conversationIds->isEmpty()) {
            return 0;
        }

        $gaps = [];

        Message::whereIn('conversation_id', $conversationIds)
            ->orderBy('conversation_id')
            ->orderBy('created_at')
            ->get(['conversation_id', 'direction', 'created_at'])
            ->groupBy('conversation_id')
            ->each(function ($messages) use (&$gaps) {
                $pendingInboundAt = null;
                foreach ($messages as $m) {
                    if ($m->direction === 'inbound') {
                        $pendingInboundAt = $m->created_at;
                    } elseif ($m->direction === 'outbound' && $pendingInboundAt) {
                        $gaps[] = $m->created_at->diffInSeconds($pendingInboundAt);
                        $pendingInboundAt = null;
                    }
                }
            });

        if (empty($gaps)) {
            return 0;
        }

        return array_sum($gaps) / count($gaps);
    }

    /**
     * Get AI performance metrics
     */
    public function aiPerformance(Request $request)
    {
        $totalMessages = $this->userMessages()->count();
        $autoReplies = $this->userMessages()
            ->where('is_ai', true)
            ->count();

        $manualInterventions = $this->userMessages()
            ->where('is_ai', false)
            ->where('direction', 'outbound')
            ->count();

        $avgResponseTime = $this->averageResponseTimeSeconds();

        $responseRate = $totalMessages > 0 ? ($autoReplies / $totalMessages) * 100 : 0;

        return response()->json([
            'total_messages' => $totalMessages,
            'auto_replies' => $autoReplies,
            'auto_reply_rate' => round($responseRate, 1),
            'manual_interventions' => $manualInterventions,
            'avg_response_time_seconds' => round($avgResponseTime, 1),
            'avg_response_time_formatted' => $avgResponseTime < 60
                ? round($avgResponseTime, 1) . 's'
                : round($avgResponseTime / 60, 1) . 'm',
        ]);
    }

    /**
     * Get top questions from conversations using improved detection
     */
    public function topQuestions(Request $request)
    {
        $limit = $request->get('limit', 10);

        // Get all inbound messages for analysis
        $messages = $this->userMessages()
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
                '/^(what|where|when|why|how|who|which|whose|is|are|do|does|did|can|could|would|should|will)/i',
                // Question words at start (Arabic)
                '/^(ما|متى|أين|كيف|لماذا|من|كم|هل|هل يمكن|هل كان)/i',
                // Short queries (less than 100 chars)
                '/^.{1,100}$/',
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
        $autoReplies = $this->userMessages()
            ->where('is_ai', true)
            ->count();

        // Use configurable values or defaults
        $manualReplyTimeMinutes = config('services.metrics.avg_manual_reply_time', 3);
        $totalTimeSavedMinutes = $autoReplies * $manualReplyTimeMinutes;
        $totalTimeSavedHours = round($totalTimeSavedMinutes / 60, 1);

        // Estimate value using configurable hourly rate
        $hourlyRate = config('services.metrics.hourly_rate', 100);
        $estimatedValue = round($totalTimeSavedHours * $hourlyRate);

        return response()->json([
            'messages_handled' => $autoReplies,
            'avg_manual_reply_time' => $manualReplyTimeMinutes . ' ' . ($request->get('lang') === 'ar' ? 'دقائق' : 'min'),
            'time_saved_hours' => $totalTimeSavedHours,
            'estimated_value' => $estimatedValue,
            'estimated_value_formatted' => $estimatedValue . ' ' . ($request->get('lang') === 'ar' ? 'ريال' : 'SAR'),
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
        $totalMessages = $this->userMessages()->count();
        $aiReplies = $this->userMessages()
            ->where('is_ai', true)
            ->count();
        $responseRate = $totalMessages > 0 ? round(($aiReplies / $totalMessages) * 100, 1) : 0;

        // Time saved: use configurable reply time
        $replyTimeMinutes = config('services.metrics.avg_manual_reply_time', 3);
        $hoursSaved = round(($aiReplies * $replyTimeMinutes) / 60, 1);

        // Week-over-week message trend
        $thisWeek = $this->userMessages()
            ->where('created_at', '>=', now()->subDays(7))
            ->count();
        $lastWeek = $this->userMessages()
            ->whereBetween('created_at', [now()->subDays(14), now()->subDays(7)])
            ->count();
        $messagesTrend = null;
        if ($lastWeek > 0) {
            $change = round((($thisWeek - $lastWeek) / $lastWeek) * 100, 1);
            $messagesTrend = ['value' => abs($change), 'isPositive' => $change >= 0];
        }

        // Top question this week (simple heuristic: incoming messages ending in '?')
        $topQuestionRow = $this->userMessages()
            ->where('direction', 'inbound')
            ->where('created_at', '>=', now()->subDays(7))
            ->where('content', 'like', '%?')
            ->select(DB::raw('content'), DB::raw('COUNT(*) as count'))
            ->groupBy('content')
            ->orderByDesc('count')
            ->first();

        return response()->json([
            'total_messages' => $totalMessages,
            'ai_replies' => $aiReplies,
            'response_rate' => $responseRate,
            'hours_saved' => $hoursSaved,
            'messages_trend' => $messagesTrend,
            'top_question' => $topQuestionRow->content ?? null,
            'question_count' => $topQuestionRow->count ?? 0,
        ]);
    }

    /**
     * Export reports as CSV
     */
    public function exportCsv(Request $request)
    {
        $user = Auth::user();
        $type = $request->get('type', 'messages'); // messages, channels, ai_performance

        $filename = "report_{$type}_" . now()->format('Y-m-d') . '.csv';
        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ];

        $callback = function () use ($user, $type) {
            $file = fopen('php://output', 'w');

            if ($type === 'messages') {
                fputcsv($file, ['Date', 'Direction', 'Is AI', 'Content', 'Channel Type']);
                
                $this->userMessages()
                    ->with('conversation.channel')
                    ->orderBy('created_at', 'desc')
                    ->chunk(1000, function ($messages) use ($file) {
                        foreach ($messages as $msg) {
                            fputcsv($file, [
                                $msg->created_at,
                                $msg->direction,
                                $msg->is_ai ? 'Yes' : 'No',
                                substr($msg->content, 0, 500),
                                $msg->conversation->channel->type ?? 'Unknown',
                            ]);
                        }
                    });
            } elseif ($type === 'channels') {
                fputcsv($file, ['Channel Type', 'Channel Name', 'Message Count', 'Status']);
                
                Channel::where('user_id', $user->id)
                    ->withCount('messages')
                    ->get()
                    ->each(function ($channel) use ($file) {
                        fputcsv($file, [
                            $channel->type,
                            $channel->page_name ?? 'N/A',
                            $channel->messages_count,
                            $channel->status,
                        ]);
                    });
            } elseif ($type === 'ai_performance') {
                fputcsv($file, ['Metric', 'Value']);
                
                $totalMessages = $this->userMessages()->count();
                $autoReplies = $this->userMessages()->where('is_ai', true)->count();
                $manualInterventions = $this->userMessages()
                    ->where('is_ai', false)
                    ->where('direction', 'outbound')
                    ->count();
                $avgResponseTime = $this->averageResponseTimeSeconds();
                $responseRate = $totalMessages > 0 ? ($autoReplies / $totalMessages) * 100 : 0;

                fputcsv($file, ['Total Messages', $totalMessages]);
                fputcsv($file, ['AI Auto Replies', $autoReplies]);
                fputcsv($file, ['Manual Interventions', $manualInterventions]);
                fputcsv($file, ['Auto Reply Rate (%)', round($responseRate, 1)]);
                fputcsv($file, ['Avg Response Time (seconds)', round($avgResponseTime, 1)]);
            }

            fclose($file);
        };

        return response()->stream($callback, 200, $headers);
    }

    /**
     * Export reports as PDF using FPDF library
     */
    public function exportPdf(Request $request)
    {
        $user = Auth::user();
        $type = $request->get('type', 'messages');

        // Create new PDF document
        $pdf = new \FPDF();
        $pdf->AddPage();
        
        // Set font
        $pdf->SetFont('Arial', '', 12);
        
        // Add header
        $pdf->SetFont('Arial', 'B', 16);
        $pdf->Cell(0, 10, ucfirst($type) . ' Report', 0, 1, 'C');
        $pdf->SetFont('Arial', '', 10);
        $pdf->Cell(0, 6, 'Generated: ' . now()->toDateTimeString(), 0, 1, 'C');
        $pdf->Cell(0, 6, 'User: ' . $user->name . ' (' . $user->email . ')', 0, 1, 'C');
        $pdf->Ln(10);
        
        // Add separator line
        $pdf->Line(10, $pdf->GetY(), 200, $pdf->GetY());
        $pdf->Ln(10);

        if ($type === 'messages') {
            $pdf->SetFont('Arial', 'B', 14);
            $pdf->Cell(0, 10, 'Message Report', 0, 1);
            $pdf->SetFont('Arial', '', 10);
            
            $messages = $this->userMessages()
                ->with('conversation.channel')
                ->orderBy('created_at', 'desc')
                ->limit(100)
                ->get();
            
            // Table header
            $pdf->SetFont('Arial', 'B', 10);
            $pdf->Cell(30, 7, 'Date', 1);
            $pdf->Cell(20, 7, 'Direction', 1);
            $pdf->Cell(15, 7, 'AI', 1);
            $pdf->Cell(25, 7, 'Channel', 1);
            $pdf->Cell(100, 7, 'Content', 1);
            $pdf->Ln();
            
            // Table data
            $pdf->SetFont('Arial', '', 9);
            foreach ($messages as $msg) {
                $pdf->Cell(30, 7, $msg->created_at->format('Y-m-d H:i'), 1);
                $pdf->Cell(20, 7, $msg->direction, 1);
                $pdf->Cell(15, 7, $msg->is_ai ? 'Yes' : 'No', 1);
                $pdf->Cell(25, 7, $msg->conversation->channel->type ?? 'Unknown', 1);
                $pdf->Cell(100, 7, substr($msg->content, 0, 50) . '...', 1);
                $pdf->Ln();
            }
            
        } elseif ($type === 'channels') {
            $pdf->SetFont('Arial', 'B', 14);
            $pdf->Cell(0, 10, 'Channel Report', 0, 1);
            $pdf->SetFont('Arial', '', 10);
            
            $channels = Channel::where('user_id', $user->id)
                ->withCount('messages')
                ->get();
            
            // Table header
            $pdf->SetFont('Arial', 'B', 10);
            $pdf->Cell(30, 7, 'Type', 1);
            $pdf->Cell(50, 7, 'Name', 1);
            $pdf->Cell(30, 7, 'Messages', 1);
            $pdf->Cell(30, 7, 'Status', 1);
            $pdf->Ln();
            
            // Table data
            $pdf->SetFont('Arial', '', 10);
            foreach ($channels as $channel) {
                $pdf->Cell(30, 7, $channel->type, 1);
                $pdf->Cell(50, 7, $channel->page_name ?? 'N/A', 1);
                $pdf->Cell(30, 7, $channel->messages_count, 1);
                $pdf->Cell(30, 7, $channel->status, 1);
                $pdf->Ln();
            }
            
        } elseif ($type === 'ai_performance') {
            $pdf->SetFont('Arial', 'B', 14);
            $pdf->Cell(0, 10, 'AI Performance Report', 0, 1);
            $pdf->SetFont('Arial', '', 10);
            
            $totalMessages = $this->userMessages()->count();
            $autoReplies = $this->userMessages()->where('is_ai', true)->count();
            $manualInterventions = $this->userMessages()
                ->where('is_ai', false)
                ->where('direction', 'outbound')
                ->count();
            $avgResponseTime = $this->averageResponseTimeSeconds();
            $responseRate = $totalMessages > 0 ? ($autoReplies / $totalMessages) * 100 : 0;

            $pdf->Cell(60, 10, 'Total Messages:', 0);
            $pdf->Cell(40, 10, $totalMessages, 0, 1);
            $pdf->Cell(60, 10, 'AI Auto Replies:', 0);
            $pdf->Cell(40, 10, $autoReplies, 0, 1);
            $pdf->Cell(60, 10, 'Manual Interventions:', 0);
            $pdf->Cell(40, 10, $manualInterventions, 0, 1);
            $pdf->Cell(60, 10, 'Auto Reply Rate:', 0);
            $pdf->Cell(40, 10, round($responseRate, 1) . '%', 0, 1);
            $pdf->Cell(60, 10, 'Avg Response Time:', 0);
            $pdf->Cell(40, 10, round($avgResponseTime, 1) . ' seconds', 0, 1);
        }

        // Generate PDF content using proper FPDF output
        $filename = "report_{$type}_" . now()->format('Y-m-d') . '.pdf';
        
        return response()->streamDownload(function() use ($pdf) {
            $pdf->Output('D', $filename);
        }, $filename, [
            'Content-Type' => 'application/pdf',
        ]);
    }
}
