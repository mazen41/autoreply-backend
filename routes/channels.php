<?php

use Illuminate\Support\Facades\Broadcast;

// Each user only gets to listen on their own private inbox channel.
// The auth:sanctum middleware (registered in bootstrap/app.php) makes
// sure request()->user() resolves from the Bearer token.
Broadcast::channel('inbox.{userId}', function ($user, $userId) {
    return (int) $user->id === (int) $userId;
});
