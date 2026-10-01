<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Conversation;
use Illuminate\Support\Facades\Log;

class ChurnDetectionService
{
    /**
     * Analyze customer churn risk.
     *
     * @param Customer $customer
     * @return array Risk assessment result
     */
    public static function analyzeCustomerRisk(Customer $customer): array
    {
        $riskScore = 0;
        $reasons = [];

        // Factor 1: Inactivity (0-40 points)
        $lastConversation = Conversation::where('customer_id', $customer->id)
            ->orderBy('last_message_at', 'desc')
            ->first();

        $daysSinceLastActivity = $lastConversation
            ? now()->diffInDays($lastConversation->last_message_at)
            : 999;

        if ($daysSinceLastActivity > 60) {
            $riskScore += 40;
            $reasons[] = 'No activity in 60+ days';
        } elseif ($daysSinceLastActivity > 30) {
            $riskScore += 25;
            $reasons[] = 'No activity in 30+ days';
        } elseif ($daysSinceLastActivity > 14) {
            $riskScore += 10;
            $reasons[] = 'No activity in 14+ days';
        }

        // Factor 2: Unresolved escalations (0-30 points)
        $unresolvedEscalations = Conversation::where('customer_id', $customer->id)
            ->where('requires_human', true)
            ->where('status', '!=', 'closed')
            ->count();

        if ($unresolvedEscalations > 2) {
            $riskScore += 30;
            $reasons[] = 'Multiple unresolved escalations';
        } elseif ($unresolvedEscalations > 0) {
            $riskScore += 15;
            $reasons[] = 'Unresolved escalation';
        }

        // Factor 3: Negative sentiment history (0-20 points)
        $negativeFeedback = \App\Models\MessageFeedback::whereHas('message.conversation', function ($q) use ($customer) {
                $q->where('customer_id', $customer->id);
            })
            ->where('feedback', 'negative')
            ->count();

        if ($negativeFeedback > 3) {
            $riskScore += 20;
            $reasons[] = 'Multiple negative feedback';
        } elseif ($negativeFeedback > 0) {
            $riskScore += 10;
            $reasons[] = 'Negative feedback received';
        }

        // Factor 4: Declining order frequency (0-10 points)
        $recentOrders = Conversation::where('customer_id', $customer->id)
            ->where('checkout_state->status', 'completed')
            ->where('created_at', '>=', now()->subMonths(3))
            ->count();

        if ($recentOrders === 0) {
            $riskScore += 10;
            $reasons[] = 'No orders in last 3 months';
        }

        // Determine risk level
        $riskLevel = match (true) {
            $riskScore >= 60 => 'high',
            $riskScore >= 30 => 'medium',
            default => 'low',
        };

        $result = [
            'customer_id' => $customer->id,
            'churn_risk_score' => min(100, $riskScore),
            'risk_level' => $riskLevel,
            'churn_reasons' => $reasons,
            'days_since_last_activity' => $daysSinceLastActivity,
            'unresolved_escalations' => $unresolvedEscalations,
            'negative_feedback_count' => $negativeFeedback,
            'analyzed_at' => now()->toISOString(),
        ];

        // Save risk metrics to customer record
        $customer->update(['lead_score' => 100 - min(100, $riskScore)]);

        // Trigger notification for high-risk customers
        if ($riskLevel === 'high') {
            self::notifyHighRiskCustomer($customer, $result);
        }

        Log::info('Churn risk analyzed', [
            'customer_id' => $customer->id,
            'risk_level' => $riskLevel,
            'risk_score' => $riskScore,
        ]);

        return $result;
    }

    /**
     * Notify business owner about high-risk customer.
     */
    private static function notifyHighRiskCustomer(Customer $customer, array $riskData): void
    {
        try {
            $notificationService = new \App\Services\NotificationService();
            $notificationService->churnRiskAlert(
                $customer->businessProfile->user_id,
                $customer->name ?? 'Customer #' . $customer->id,
                $riskData
            );
        } catch (\Exception $e) {
            Log::warning('Failed to send churn risk notification: ' . $e->getMessage());
        }
    }

    /**
     * Batch analyze all customers for a business.
     */
    public static function batchAnalyze(int $businessId): array
    {
        $customers = Customer::where('business_profile_id', $businessId)->get();
        $results = [];

        foreach ($customers as $customer) {
            $results[] = self::analyzeCustomerRisk($customer);
        }

        return [
            'total_analyzed' => count($results),
            'high_risk' => count(array_filter($results, fn($r) => $r['risk_level'] === 'high')),
            'medium_risk' => count(array_filter($results, fn($r) => $r['risk_level'] === 'medium')),
            'low_risk' => count(array_filter($results, fn($r) => $r['risk_level'] === 'low')),
            'details' => $results,
        ];
    }
}
