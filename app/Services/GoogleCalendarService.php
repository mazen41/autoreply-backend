<?php

namespace App\Services;

use App\Models\CalendarEvent;
use App\Models\BusinessProfile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class GoogleCalendarService
{
    private $clientId;
    private $clientSecret;
    private $redirectUri;

    public function __construct()
    {
        $this->clientId = config('services.google.client_id');
        $this->clientSecret = config('services.google.client_secret');
        $this->redirectUri = config('services.google.redirect_uri');
    }

    /**
     * Get OAuth authorization URL
     */
    public function getAuthUrl(int $businessId): string
    {
        $state = $businessId;
        
        $params = [
            'client_id' => $this->clientId,
            'redirect_uri' => $this->redirectUri,
            'scope' => 'https://www.googleapis.com/auth/calendar.events',
            'response_type' => 'code',
            'access_type' => 'offline',
            'state' => $state,
        ];

        return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query($params);
    }

    /**
     * Exchange authorization code for access token
     */
    public function exchangeCodeForToken(string $code): array
    {
        $response = Http::post('https://oauth2.googleapis.com/token', [
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'code' => $code,
            'grant_type' => 'authorization_code',
            'redirect_uri' => $this->redirectUri,
        ]);

        if (!$response->successful()) {
            Log::error('Google OAuth token exchange failed', [
                'error' => $response->body(),
            ]);
            return ['success' => false, 'error' => 'Token exchange failed'];
        }

        return [
            'success' => true,
            'access_token' => $response->json('access_token'),
            'refresh_token' => $response->json('refresh_token'),
            'expires_in' => $response->json('expires_in'),
        ];
    }

    /**
     * Refresh access token
     */
    public function refreshToken(string $refreshToken): array
    {
        $response = Http::post('https://oauth2.googleapis.com/token', [
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'refresh_token' => $refreshToken,
            'grant_type' => 'refresh_token',
        ]);

        if (!$response->successful()) {
            Log::error('Google OAuth token refresh failed', [
                'error' => $response->body(),
            ]);
            return ['success' => false, 'error' => 'Token refresh failed'];
        }

        return [
            'success' => true,
            'access_token' => $response->json('access_token'),
            'expires_in' => $response->json('expires_in'),
        ];
    }

    /**
     * Create calendar event
     */
    public function createEvent(int $businessId, array $eventData): array
    {
        $business = BusinessProfile::find($businessId);
        
        if (!$business || !$business->google_access_token) {
            return ['success' => false, 'error' => 'Google not connected'];
        }

        $accessToken = $this->getValidAccessToken($business);

        $calendarId = 'primary';
        $event = [
            'summary' => $eventData['title'],
            'description' => $eventData['description'] ?? '',
            'start' => [
                'dateTime' => $eventData['start_time'],
                'timeZone' => $business->timezone ?? 'UTC',
            ],
            'end' => [
                'dateTime' => $eventData['end_time'],
                'timeZone' => $business->timezone ?? 'UTC',
            ],
        ];

        if (isset($eventData['attendees'])) {
            $event['attendees'] = array_map(function ($email) {
                return ['email' => $email];
            }, $eventData['attendees']);
        }

        $response = Http::withToken($accessToken)
            ->post("https://www.googleapis.com/calendar/v3/calendars/{$calendarId}/events", $event);

        if (!$response->successful()) {
            Log::error('Google Calendar event creation failed', [
                'error' => $response->body(),
            ]);
            return ['success' => false, 'error' => 'Event creation failed'];
        }

        return [
            'success' => true,
            'event_id' => $response->json('id'),
            'html_link' => $response->json('htmlLink'),
        ];
    }

    /**
     * Get available time slots
     */
    public function getAvailableSlots(int $businessId, string $date, int $duration = 30): array
    {
        $business = BusinessProfile::find($businessId);
        
        if (!$business || !$business->google_access_token) {
            return ['success' => false, 'error' => 'Google not connected'];
        }

        $accessToken = $this->getValidAccessToken($business);
        $calendarId = 'primary';

        $timeMin = "{$date}T00:00:00Z";
        $timeMax = "{$date}T23:59:59Z";

        $response = Http::withToken($accessToken)
            ->get("https://www.googleapis.com/calendar/v3/calendars/{$calendarId}/events", [
                'timeMin' => $timeMin,
                'timeMax' => $timeMax,
                'singleEvents' => true,
                'orderBy' => 'startTime',
            ]);

        if (!$response->successful()) {
            return ['success' => false, 'error' => 'Failed to fetch events'];
        }

        $events = $response->json('items', []);
        $busySlots = [];

        foreach ($events as $event) {
            if (isset($event['start']['dateTime']) && isset($event['end']['dateTime'])) {
                $busySlots[] = [
                    'start' => $event['start']['dateTime'],
                    'end' => $event['end']['dateTime'],
                ];
            }
        }

        // Calculate available slots
        $availableSlots = $this->calculateAvailableSlots($date, $busySlots, $duration);

        return [
            'success' => true,
            'available_slots' => $availableSlots,
        ];
    }

    /**
     * Calculate available time slots
     */
    private function calculateAvailableSlots(string $date, array $busySlots, int $duration): array
    {
        $slots = [];
        $businessHours = ['09:00', '17:00']; // Default business hours

        // Generate slots every 30 minutes
        $startTime = strtotime("{$date} {$businessHours[0]}");
        $endTime = strtotime("{$date} {$businessHours[1]}");
        $current = $startTime;

        while ($current + ($duration * 60) <= $endTime) {
            $slotStart = date('H:i', $current);
            $slotEnd = date('H:i', $current + ($duration * 60));
            $slotStartFull = date('Y-m-d\TH:i:s\Z', $current);
            $slotEndFull = date('Y-m-d\TH:i:s\Z', $current + ($duration * 60));

            // Check if slot conflicts with busy times
            $isAvailable = true;
            foreach ($busySlots as $busy) {
                if (($slotStartFull >= $busy['start'] && $slotStartFull < $busy['end']) ||
                    ($slotEndFull > $busy['start'] && $slotEndFull <= $busy['end']) ||
                    ($slotStartFull <= $busy['start'] && $slotEndFull >= $busy['end'])) {
                    $isAvailable = false;
                    break;
                }
            }

            if ($isAvailable) {
                $slots[] = [
                    'start' => $slotStart,
                    'end' => $slotEnd,
                    'start_full' => $slotStartFull,
                    'end_full' => $slotEndFull,
                ];
            }

            $current += 1800; // Add 30 minutes
        }

        return $slots;
    }

    /**
     * Get valid access token (refresh if needed)
     */
    private function getValidAccessToken(BusinessProfile $business): string
    {
        $cacheKey = "google_token_{$business->id}";
        
        $token = Cache::get($cacheKey);
        
        if ($token) {
            return $token;
        }

        // Refresh token
        $result = $this->refreshToken($business->google_refresh_token);
        
        if ($result['success']) {
            $business->update([
                'google_access_token' => $result['access_token'],
            ]);
            
            Cache::put($cacheKey, $result['access_token'], $result['expires_in'] - 60);
            
            return $result['access_token'];
        }

        throw new \Exception('Failed to refresh Google token');
    }

    /**
     * Disconnect Google Calendar
     */
    public function disconnect(int $businessId): bool
    {
        $business = BusinessProfile::find($businessId);
        
        if (!$business) {
            return false;
        }

        $business->update([
            'google_access_token' => null,
            'google_refresh_token' => null,
        ]);

        Cache::forget("google_token_{$businessId}");

        Log::info('Google Calendar disconnected', ['business_id' => $businessId]);
        
        return true;
    }
}
