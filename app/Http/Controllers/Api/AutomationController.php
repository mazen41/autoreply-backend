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

    /**
     * Get comment automation settings for a business
     */
    public function getCommentSettings(Request $request, $businessId)
    {
        $business = BusinessProfile::where('user_id', Auth::id())
            ->findOrFail($businessId);

        return response()->json([
            'comment_automation_enabled' => $business->comment_automation_enabled,
            'instagram_comments_enabled' => $business->instagram_comments_enabled,
            'facebook_comments_enabled' => $business->facebook_comments_enabled,
            'reply_mode' => $business->reply_mode,
            'confidence_threshold' => $business->confidence_threshold,
            'reply_language' => $business->reply_language,
            'max_reply_length' => $business->max_reply_length,
            'use_knowledge' => $business->use_knowledge,
            'use_products' => $business->use_products,
            'use_prices' => $business->use_prices,
            'use_inventory' => $business->use_inventory,
            'use_orders' => $business->use_orders,
            'use_shipping' => $business->use_shipping,
            'use_policies' => $business->use_policies,
            'ignore_spam' => $business->ignore_spam,
            'ignore_offensive' => $business->ignore_offensive,
            'ignore_competitors' => $business->ignore_competitors,
            'blocked_keywords' => $business->blocked_keywords,
            'emoji_enabled' => $business->emoji_enabled,
        ]);
    }

    /**
     * Update comment automation settings for a business
     */
    public function updateCommentSettings(Request $request, $businessId)
    {
        $request->validate([
            'comment_automation_enabled' => 'boolean',
            'instagram_comments_enabled' => 'boolean',
            'facebook_comments_enabled' => 'boolean',
            'reply_mode' => 'in:public_comment,public_reply_private_message,private_message',
            'confidence_threshold' => 'integer|min:0|max:100',
            'reply_language' => 'in:automatic,arabic,english,same_as_customer',
            'max_reply_length' => 'integer|min:1',
            'use_knowledge' => 'boolean',
            'use_products' => 'boolean',
            'use_prices' => 'boolean',
            'use_inventory' => 'boolean',
            'use_orders' => 'boolean',
            'use_shipping' => 'boolean',
            'use_policies' => 'boolean',
            'ignore_spam' => 'boolean',
            'ignore_offensive' => 'boolean',
            'ignore_competitors' => 'boolean',
            'blocked_keywords' => 'nullable|string', // JSON array or comma-separated string
            'emoji_enabled' => 'boolean',
        ]);

        $business = BusinessProfile::where('user_id', Auth::id())
            ->findOrFail($businessId);

        $business->update($request->only([
            'comment_automation_enabled',
            'instagram_comments_enabled',
            'facebook_comments_enabled',
            'reply_mode',
            'confidence_threshold',
            'reply_language',
            'max_reply_length',
            'use_knowledge',
            'use_products',
            'use_prices',
            'use_inventory',
            'use_orders',
            'use_shipping',
            'use_policies',
            'ignore_spam',
            'ignore_offensive',
            'ignore_competitors',
            'blocked_keywords',
            'emoji_enabled',
        ]));

        return response()->json(['success' => true]);
    }
}
