<?php

namespace App\Services;

use App\Models\BusinessHour;
use App\Models\AutoMessage;
use App\Models\BusinessProfile;
use Carbon\Carbon;

class BusinessHoursService
{
    /**
     * Check if business is currently open
     */
    public function isBusinessOpen(BusinessProfile $business): bool
    {
        $now = Carbon::now($business->timezone ?? 'UTC');
        $dayOfWeek = $now->dayOfWeek; // 0 (Sunday) to 6 (Saturday)
        $currentTime = $now->format('H:i:s');

        $businessHour = BusinessHour::where('business_id', $business->id)
            ->where('day_of_week', $dayOfWeek)
            ->where('is_active', true)
            ->first();

        if (!$businessHour) {
            // No hours set for this day, assume closed
            return false;
        }

        return $currentTime >= $businessHour->start_time && $currentTime <= $businessHour->end_time;
    }

    /**
     * Get away message for business
     */
    public function getAwayMessage(BusinessProfile $business): ?string
    {
        $autoMessage = AutoMessage::where('business_id', $business->id)
            ->where('type', 'away')
            ->where('is_enabled', true)
            ->first();

        return $autoMessage ? $autoMessage->message : null;
    }

    /**
     * Check if AI should be disabled during off hours
     */
    public function shouldDisableAI(BusinessProfile $business): bool
    {
        // This can be configured per business
        return $business->business_hours_enabled ?? false;
    }

    /**
     * Get next opening time
     */
    public function getNextOpeningTime(BusinessProfile $business): ?Carbon
    {
        $now = Carbon::now($business->timezone ?? 'UTC');
        
        // Check next 7 days for opening times
        for ($i = 0; $i < 7; $i++) {
            $checkDate = $now->copy()->addDays($i);
            $dayOfWeek = $checkDate->dayOfWeek;
            
            $businessHour = BusinessHour::where('business_id', $business->id)
                ->where('day_of_week', $dayOfWeek)
                ->where('is_active', true)
                ->first();

            if ($businessHour) {
                if ($i === 0) {
                    // Today - check if already past opening time
                    $currentTime = $now->format('H:i:s');
                    if ($currentTime < $businessHour->start_time) {
                        return $checkDate->setTimeFromTimeString($businessHour->start_time);
                    }
                } else {
                    // Future day
                    return $checkDate->setTimeFromTimeString($businessHour->start_time);
                }
            }
        }

        return null;
    }
}
