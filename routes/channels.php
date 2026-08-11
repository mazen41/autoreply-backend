<?php

use Illuminate\Support\Facades\Broadcast;

/*
|--------------------------------------------------------------------------
| Broadcast Channels
|--------------------------------------------------------------------------
|
| Here you may register all of the event broadcasting channels that your
| application supports. The given channel authorization callbacks are
| used to check if an authenticated user has the authority to broadcast
| on the channel.
|
*/

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

Broadcast::channel('business.{businessId}', function ($user, $businessId) {
    // Only allow business owner and team members to subscribe
    $business = \App\Models\BusinessProfile::find($businessId);
    if (!$business) {
        return false;
    }

    return $business->user_id === $user->id || 
           $business->teamMembers()->where('user_id', $user->id)->exists();
});

Broadcast::channel('conversation.{conversationId}', function ($user, $conversationId) {
    // Only allow business members to subscribe to conversation events
    $conversation = \App\Models\Conversation::find($conversationId);
    if (!$conversation) {
        return false;
    }

    $business = $conversation->business;
    return $business->user_id === $user->id || 
           $business->teamMembers()->where('user_id', $user->id)->exists();
});
