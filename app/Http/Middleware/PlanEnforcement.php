<?php

namespace App\Http\Middleware;

use App\Models\Bot;
use App\Models\Channel;
use App\Models\Package;
use App\Models\TeamMember;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class PlanEnforcement
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next, string $limitType): Response
    {
        $user = $request->user();
        $businessId = $user?->business_id;

        if (!$businessId) {
            return response()->json([
                'error' => 'No business profile found',
                'error_code' => 'NO_BUSINESS_PROFILE',
            ], 400);
        }

        // Get the user's active subscription and package
        $subscription = $user->activeSubscription;
        $package = $subscription?->package ?? Package::where('name', 'Free')->first();

        if (!$package) {
            return response()->json([
                'error' => 'No package found',
                'error_code' => 'NO_PACKAGE',
            ], 400);
        }

        $maxAllowed = match ($limitType) {
            'max_bots' => $package->max_bots ?? PHP_INT_MAX,
            'max_channels' => $package->max_channels ?? PHP_INT_MAX,
            'max_team_members' => $package->max_team_members ?? PHP_INT_MAX,
            'max_monthly_messages' => $package->max_monthly_messages ?? PHP_INT_MAX,
            default => PHP_INT_MAX,
        };

        $currentUsage = match ($limitType) {
            'max_bots' => Bot::where('business_profile_id', $businessId)->count(),
            'max_channels' => Channel::where('business_id', $businessId)->count(),
            'max_team_members' => TeamMember::where('business_id', $businessId)->count(),
            'max_monthly_messages' => $this->getMonthlyMessageCount($businessId),
            default => 0,
        };

        if ($currentUsage >= $maxAllowed) {
            return response()->json([
                'error' => "Limit Reached: Please upgrade your subscription plan to create more {$this->getLimitLabel($limitType)}.",
                'error_code' => 'PLAN_LIMIT_EXCEEDED',
                'limit_type' => $limitType,
                'current_usage' => $currentUsage,
                'max_allowed' => $maxAllowed,
                'upgrade_url' => '/dashboard/settings/billing',
            ], 403);
        }

        return $next($request);
    }

    /**
     * Get the monthly message count for a business.
     */
    private function getMonthlyMessageCount(int $businessId): int
    {
        return \App\Models\Message::whereHas('conversation', function ($q) use ($businessId) {
                $q->where('business_id', $businessId);
            })
            ->where('created_at', '>=', now()->startOfMonth())
            ->count();
    }

    /**
     * Get a human-readable label for the limit type.
     */
    private function getLimitLabel(string $limitType): string
    {
        return match ($limitType) {
            'max_bots' => 'AI bots',
            'max_channels' => 'channels',
            'max_team_members' => 'team members',
            'max_monthly_messages' => 'monthly messages',
            default => 'resources',
        };
    }
}
