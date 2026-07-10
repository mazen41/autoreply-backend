<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\OnboardingController;
use App\Http\Controllers\Api\SocialAuthController;
use App\Http\Controllers\Api\PackageController;
use App\Http\Controllers\Api\SubscriptionController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\WebhookController;
use App\Http\Controllers\ChannelController;
use App\Http\Controllers\GmailController;
use App\Http\Controllers\InboxController;
use App\Http\Controllers\PostController;
use Illuminate\Support\Facades\Route;

// â”€â”€ Public auth routes â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
Route::prefix('auth')->group(function () {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login',    [AuthController::class, 'login']);
    
    // Social login routes
    Route::get('/google/redirect',  [SocialAuthController::class, 'redirectToGoogle']);
    Route::get('/google/callback',  [SocialAuthController::class, 'handleGoogleCallback']);
    Route::get('/facebook/redirect', [SocialAuthController::class, 'redirectToFacebook']);
    Route::get('/facebook/callback', [SocialAuthController::class, 'handleFacebookCallback']);
});

// â”€â”€ Meta Webhook â€” public, Meta calls these directly â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
Route::get('/webhook/meta',  [WebhookController::class, 'verify']);
Route::post('/webhook/meta', [WebhookController::class, 'handle']);

// â”€â”€ Public OAuth channels callbacks â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
Route::get('/channels/connect/facebook',  [ChannelController::class, 'connectFacebook']);
Route::get('/channels/callback/facebook', [ChannelController::class, 'callbackFacebook']);
Route::get('/channels/callback/gmail',    [GmailController::class, 'callback']);

// â”€â”€ Protected routes â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/auth/user',    [AuthController::class, 'user']);

    // Onboarding
    Route::prefix('onboarding')->group(function () {
        Route::post('/step1',    [OnboardingController::class, 'step1']);
        Route::post('/step2',    [OnboardingController::class, 'step2']);
        Route::post('/step3',    [OnboardingController::class, 'step3']);
        Route::post('/step4',    [OnboardingController::class, 'step4']);
        Route::post('/complete', [OnboardingController::class, 'complete']);
    });

    // Channels â€” listing and disconnect
    Route::get('/channels/connect/gmail',     [GmailController::class, 'connect']);
    Route::get('/channels/gmail/fetch',         [GmailController::class, 'fetchEmails']);
    Route::get('/channels',                   [ChannelController::class, 'index']);
    Route::patch('/channels/{id}',            [ChannelController::class, 'update']);
    Route::delete('/channels/{id}',           [ChannelController::class, 'disconnect']);

    // Inbox â€” conversations + messages + manual reply
    Route::get('/inbox',                                [InboxController::class, 'index']);
    Route::get('/inbox/{conversationId}/messages',      [InboxController::class, 'messages']);
    Route::post('/inbox/{conversationId}/reply',        [InboxController::class, 'reply']);
});

// Gmail Webhook - public, Google Pub/Sub calls this
Route::post('/webhook/gmail', [WebhookController::class, 'handleGmail']);

// Public blog routes
Route::get('/posts', [PostController::class, 'index']);
Route::get('/posts/{slug}', [PostController::class, 'show']);

// Protected blog admin routes
Route::middleware(['auth:sanctum', 'admin'])->group(function () {
    Route::post('/posts', [PostController::class, 'store']);
    Route::patch('/posts/{id}/publish', [PostController::class, 'publish']);
    Route::patch('/posts/{id}/reject', [PostController::class, 'reject']);
});

// Public blog approval webhooks
Route::get('/blog/approve/{id}', [PostController::class, 'approveWebhook']);
Route::get('/blog/reject/{id}', [PostController::class, 'rejectWebhook']);

// Public package routes
Route::get('/packages', [PackageController::class, 'index']);
Route::get('/packages/{id}', [PackageController::class, 'show']);

// Payment callback (public - Moyasar redirects here)
Route::get('/payments/callback', [PaymentController::class, 'callback']);

// Moyasar webhook (public, exclude CSRF)
Route::post('/payments/webhook', [PaymentController::class, 'webhook'])->withoutMiddleware([\App\Http\Middleware\VerifyCsrfToken::class]);

// Protected subscription and payment routes
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/subscriptions/current', [SubscriptionController::class, 'current']);
    Route::delete('/subscriptions', [SubscriptionController::class, 'cancel']);
    Route::post('/payments/create', [PaymentController::class, 'createPayment']);
});
