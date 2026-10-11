<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\BusinessProfile;
use Illuminate\Support\Facades\Auth;

class AutomationRunControl
{
    public function isBusinessPaused(int|BusinessProfile $business): bool
    {
        $profile = $business instanceof BusinessProfile
            ? $business
            : BusinessProfile::query()->find($business);

        return $profile?->automation_paused_at !== null;
    }

    public function pauseCampaign(BusinessProfile $business, object $campaign, bool $paused): void
    {
        $campaign->forceFill(['paused_at' => $paused ? now() : null])->save();
        $this->audit(
            $business,
            $paused ? 'automation.campaign_paused' : 'automation.campaign_resumed',
            $campaign::class,
            (int) $campaign->id,
        );
    }

    public function setBusinessPaused(BusinessProfile $business, bool $paused): BusinessProfile
    {
        $wasPaused = $business->automation_paused_at !== null;
        if ($wasPaused === $paused) {
            return $business;
        }

        $business->forceFill(['automation_paused_at' => $paused ? now() : null])->save();
        $this->audit(
            $business,
            $paused ? 'automation.kill_switch_enabled' : 'automation.kill_switch_disabled',
            BusinessProfile::class,
            (int) $business->id,
        );

        return $business->fresh();
    }

    public function audit(BusinessProfile $business, string $action, ?string $auditableType = null, ?int $auditableId = null): void
    {
        AuditLog::query()->create([
            'user_id' => Auth::id(),
            'business_profile_id' => $business->id,
            'action' => $action,
            'auditable_type' => $auditableType,
            'auditable_id' => $auditableId,
            'payload' => null,
        ]);
    }

    public function releaseIfPaused(object $job, int|BusinessProfile $business): bool
    {
        if (!$this->isBusinessPaused($business)) {
            return false;
        }

        $job->release(60);
        return true;
    }
}
