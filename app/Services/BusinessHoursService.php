<?php

namespace App\Services;

use Carbon\Carbon;

class BusinessHoursService
{
    /**
     * Calculate the next execution time respecting business hours
     */
    public function calculateNextExecutionTime(Carbon $currentTime, int $delaySeconds, ?array $businessHours, ?string $timezone = null): Carbon
    {
        if (!$businessHours || empty($businessHours)) {
            // No business hours configured, use simple delay
            return $currentTime->copy()->addSeconds($delaySeconds);
        }

        // Apply timezone if provided
        if ($timezone) {
            $currentTime = $currentTime->copy()->setTimezone($timezone);
        }

        $targetTime = $currentTime->copy()->addSeconds($delaySeconds);
        
        // Check if target time is within business hours
        if ($this->isWithinBusinessHours($targetTime, $businessHours)) {
            return $targetTime;
        }

        // Find the next available time within business hours
        return $this->findNextBusinessHourSlot($targetTime, $businessHours);
    }

    /**
     * Check if a given time is within business hours
     */
    public function isWithinBusinessHours(Carbon $time, array $businessHours): bool
    {
        $dayOfWeek = $time->dayOfWeek; // 0 = Sunday, 6 = Saturday
        $dayConfig = $businessHours[$dayOfWeek] ?? null;

        if (!$dayConfig || !($dayConfig['enabled'] ?? false)) {
            return false; // Day is not enabled
        }

        $startTime = $dayConfig['start'] ?? '09:00';
        $endTime = $dayConfig['end'] ?? '17:00';

        $startCarbon = $time->copy()->setTimeFromTimeString($startTime);
        $endCarbon = $time->copy()->setTimeFromTimeString($endTime);

        return $time->between($startCarbon, $endCarbon);
    }

    /**
     * Alias for isWithinBusinessHours for backward compatibility
     */
    public function isBusinessOpen(Carbon $time, ?array $businessHours = null): bool
    {
        if (!$businessHours || empty($businessHours)) {
            return true; // If no business hours configured, assume always open
        }
        return $this->isWithinBusinessHours($time, $businessHours);
    }

    /**
     * Find the next available time slot within business hours
     */
    protected function findNextBusinessHourSlot(Carbon $time, array $businessHours): Carbon
    {
        $currentTime = $time->copy();
        $maxIterations = 14; // Maximum 2 weeks of searching to prevent infinite loops
        $iterations = 0;

        while ($iterations < $maxIterations) {
            $dayOfWeek = $currentTime->dayOfWeek;
            $dayConfig = $businessHours[$dayOfWeek] ?? null;

            if ($dayConfig && ($dayConfig['enabled'] ?? false)) {
                $startTime = $dayConfig['start'] ?? '09:00';
                $endTime = $dayConfig['end'] ?? '17:00';

                $startCarbon = $currentTime->copy()->setTimeFromTimeString($startTime);
                $endCarbon = $currentTime->copy()->setTimeFromTimeString($endTime);

                // If current time is before business hours start, schedule at start time
                if ($currentTime->lt($startCarbon)) {
                    return $startCarbon;
                }

                // If current time is within business hours, keep it
                if ($currentTime->between($startCarbon, $endCarbon)) {
                    return $currentTime;
                }

                // If current time is after business hours, move to next day
                $currentTime = $currentTime->addDay()->setTimeFromTimeString($startTime);
            } else {
                // Day is not enabled, move to next day
                $currentTime = $currentTime->addDay()->startOfDay();
            }

            $iterations++;
        }

        // Fallback: return the original time if no business hours found
        return $time;
    }

    /**
     * Get default business hours configuration
     */
    public function getDefaultBusinessHours(): array
    {
        return [
            0 => ['enabled' => false, 'start' => '09:00', 'end' => '17:00'], // Sunday
            1 => ['enabled' => true,  'start' => '09:00', 'end' => '17:00'], // Monday
            2 => ['enabled' => true,  'start' => '09:00', 'end' => '17:00'], // Tuesday
            3 => ['enabled' => true,  'start' => '09:00', 'end' => '17:00'], // Wednesday
            4 => ['enabled' => true,  'start' => '09:00', 'end' => '17:00'], // Thursday
            5 => ['enabled' => true,  'start' => '09:00', 'end' => '17:00'], // Friday
            6 => ['enabled' => false, 'start' => '09:00', 'end' => '17:00'], // Saturday
        ];
    }

    /**
     * Validate business hours configuration
     */
    public function validateBusinessHours(array $businessHours): array
    {
        $errors = [];

        foreach ($businessHours as $day => $config) {
            if (!is_numeric($day) || $day < 0 || $day > 6) {
                $errors[] = "Invalid day index: {$day}";
                continue;
            }

            if (!isset($config['enabled']) || !is_bool($config['enabled'])) {
                $errors[] = "Invalid enabled setting for day {$day}";
            }

            if ($config['enabled'] ?? false) {
                if (!isset($config['start']) || !$this->isValidTimeFormat($config['start'])) {
                    $errors[] = "Invalid start time for day {$day}";
                }

                if (!isset($config['end']) || !$this->isValidTimeFormat($config['end'])) {
                    $errors[] = "Invalid end time for day {$day}";
                }

                if (isset($config['start']) && isset($config['end']) && $config['start'] >= $config['end']) {
                    $errors[] = "Start time must be before end time for day {$day}";
                }
            }
        }

        return $errors;
    }

    /**
     * Check if time format is valid (HH:MM)
     */
    protected function isValidTimeFormat(string $time): bool
    {
        return preg_match('/^([01]?[0-9]|2[0-3]):[0-5][0-9]$/', $time) === 1;
    }
}
