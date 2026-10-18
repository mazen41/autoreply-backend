<?php

namespace App\Http\Middleware;

use App\Models\Bot;
use App\Models\Package;
use App\Models\TeamMember;
use App\Services\ActiveBusiness;
use App\Services\UsageMeter;
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
        $business = app(ActiveBusiness::class)->resolve($request, permission: 'write');
        $request->attributes->set('authorized_business', $business);
        $businessId = (int) $business->id;
        $usageMeter = app(UsageMeter::class);
        $subscription = $usageMeter->subscriptionFor($business);
        $package = $subscription?->package ?? Package::where('name', 'Free')->first();

        $configuredLimit = match ($limitType) {
            'max_bots' => $package->max_bots ?? PHP_INT_MAX,
            'max_channels' => $package->channels_limit ?? PHP_INT_MAX,
            'max_team_members' => $package->max_team_members ?? PHP_INT_MAX,
            'max_monthly_messages' => $package->outbound_messages_limit ?? PHP_INT_MAX,
            default => PHP_INT_MAX,
        };
        $maxAllowed = (int) $configuredLimit < 0 ? PHP_INT_MAX : (int) $configuredLimit;

        $currentUsage = match ($limitType) {
            'max_bots' => Bot::where('business_profile_id', $businessId)->count(),
            'max_channels' => $usageMeter->canUse($business, UsageMeter::CHANNELS)['used'],
            'max_team_members' => TeamMember::where('business_id', $businessId)->where('is_active', true)->count(),
            'max_monthly_messages' => $usageMeter->canUse($business, UsageMeter::OUTBOUND_MESSAGES)['used'],
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
