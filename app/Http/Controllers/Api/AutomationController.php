<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BusinessHour;
use App\Models\AutoMessage;
use App\Models\BusinessProfile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AutomationController extends Controller
{
    /**
     * Get business hours for a business
     */
    public function getBusinessHours(Request $request, $businessId)
    {
        $business = BusinessProfile::where('user_id', Auth::id())
            ->findOrFail($businessId);

        $hours = BusinessHour::where('business_id', $businessId)
            ->orderBy('day_of_week')
            ->get();

        return response()->json($hours);
    }

    /**
     * Update business hours
     */
    public function updateBusinessHours(Request $request, $businessId)
    {
        $request->validate([
            'hours' => 'required|array',
            'hours.*.day_of_week' => 'required|integer|min:0|max:6',
            'hours.*.start_time' => 'required|date_format:H:i:s',
            'hours.*.end_time' => 'required|date_format:H:i:s',
            'hours.*.is_active' => 'boolean',
        ]);

        $business = BusinessProfile::where('user_id', Auth::id())
            ->findOrFail($businessId);

        // Delete existing hours
        BusinessHour::where('business_id', $businessId)->delete();

        // Create new hours
        foreach ($request->hours as $hourData) {
            BusinessHour::create([
                'business_id' => $businessId,
                'day_of_week' => $hourData['day_of_week'],
                'start_time' => $hourData['start_time'],
                'end_time' => $hourData['end_time'],
                'is_active' => $hourData['is_active'] ?? true,
            ]);
        }

        return response()->json(['success' => true]);
    }

    /**
     * Get auto messages
     */
    public function getAutoMessages(Request $request, $businessId)
    {
        $business = BusinessProfile::where('user_id', Auth::id())
            ->findOrFail($businessId);

        $messages = AutoMessage::where('business_id', $businessId)->get();

        return response()->json($messages);
    }

    /**
     * Update auto message
     */
    public function updateAutoMessage(Request $request, $businessId)
    {
        $request->validate([
            'type' => 'required|in:away,holiday,welcome',
            'message' => 'required|string',
            'is_enabled' => 'boolean',
            'timezone' => 'string',
        ]);

        $business = BusinessProfile::where('user_id', Auth::id())
            ->findOrFail($businessId);

        $autoMessage = AutoMessage::updateOrCreate(
            [
                'business_id' => $businessId,
                'type' => $request->type,
            ],
            [
                'message' => $request->message,
                'is_enabled' => $request->is_enabled ?? true,
                'timezone' => $request->timezone ?? 'UTC',
            ]
        );

        return response()->json(['success' => true, 'auto_message' => $autoMessage]);
    }

    /**
     * Update business timezone
     */
    public function updateTimezone(Request $request, $businessId)
    {
        $request->validate([
            'timezone' => 'required|string',
        ]);

        $business = BusinessProfile::where('user_id', Auth::id())
            ->findOrFail($businessId);

        $business->update(['timezone' => $request->timezone]);

        return response()->json(['success' => true]);
    }
}
