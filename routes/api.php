<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\OnboardingController;
use App\Http\Controllers\Api\SocialAuthController;
use App\Http\Controllers\Api\PackageController;
use App\Http\Controllers\Api\SubscriptionController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\AdminController;
use App\Http\Controllers\Api\WhatsAppController;
use App\Http\Controllers\Api\ReportsController;
use App\Http\Controllers\Api\KnowledgeController;
use App\Http\Controllers\Api\TrainingController;
use App\Http\Controllers\Api\ProactiveController;
use App\Http\Controllers\Api\AutomationController;
use App\Http\Controllers\Api\ToolsController;
use App\Http\Controllers\Api\SallaWebhookController;
use App\Http\Controllers\WebhookController;
use App\Http\Controllers\ChannelController;
use App\Http\Controllers\GmailController;
use App\Http\Controllers\InboxController;
use App\Http\Controllers\PostController;
use App\Http\Controllers\N8nIntegrationController;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\RateLimiter;

// Rate limiting middleware
$rateLimitMiddleware = function ($request, $next) {
    $key = 'api:' . $request->ip() . ':' . $request->path();
    $limit = 60; // 60 requests per minute
    $decay = 60; // per minute
    
    if (RateLimiter::tooManyAttempts($key, $limit, $decay)) {
        return response()->json([
            'error' => 'Too many requests. Please slow down.',
            'retry_after' => $decay
        ], 429);
    }
    
    return $next($request);
};

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
Route::get('/channels/connect/salla',     [ChannelController::class, 'connectSalla']);
Route::get('/channels/callback/salla',    [ChannelController::class, 'callbackSalla']);

// Salla Webhook - public, Salla calls these directly
Route::post('/salla/webhook', [SallaWebhookController::class, 'handle']);

// â”€â”€ Protected routes â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/auth/user',    [AuthController::class, 'user']);
    Route::patch('/auth/profile', [AuthController::class, 'updateProfile']);
    Route::post('/auth/password', [AuthController::class, 'changePassword']);

    // Onboarding
    Route::prefix('onboarding')->group(function () {
        Route::post('/step1',    [OnboardingController::class, 'step1']);
        Route::post('/step2',    [OnboardingController::class, 'step2']);
        Route::post('/step3',    [OnboardingController::class, 'step3']);
        Route::post('/step4',    [OnboardingController::class, 'step4']);
        Route::post('/complete', [OnboardingController::class, 'complete']);
        Route::post('/upload-knowledge', [OnboardingController::class, 'uploadKnowledgeFile']);
    });

    // AI Knowledge & Instructions
    Route::prefix('knowledge')->group(function () {
        Route::get('/', [KnowledgeController::class, 'index']);
        Route::post('/upload', [KnowledgeController::class, 'upload']);
        Route::delete('/files/{id}', [KnowledgeController::class, 'delete']);
        Route::post('/instructions', [KnowledgeController::class, 'updateInstructions']);
        Route::post('/profile', [KnowledgeController::class, 'updateProfile']);
        Route::post('/test', [KnowledgeController::class, 'testResponse']);
    });

    // Channels â€” listing and disconnect
    Route::get('/channels/connect/gmail',     [GmailController::class, 'connect']);
    Route::get('/channels/gmail/fetch',         [GmailController::class, 'fetchEmails']);
    Route::get('/channels',                   [ChannelController::class, 'index']);
    Route::patch('/channels/{id}',            [ChannelController::class, 'update']);
    Route::delete('/channels/{id}',           [ChannelController::class, 'disconnect']);
    
    // Salla sync routes
    Route::post('/channels/{id}/sync/customers', function ($id) {
        \App\Jobs\SyncSallaCustomers::dispatch($id);
        return response()->json(['message' => 'Customer sync started']);
    });
    Route::post('/channels/{id}/sync/orders', function ($id) {
        \App\Jobs\SyncSallaOrders::dispatch($id);
        return response()->json(['message' => 'Order sync started']);
    });

    // Inbox — conversations + messages + manual reply
    Route::get('/inbox',                                [InboxController::class, 'index']);
    Route::get('/inbox/{conversationId}/messages',      [InboxController::class, 'messages']);
    Route::post('/inbox/{conversationId}/reply',        [InboxController::class, 'reply']);
    Route::post('/inbox/{conversationId}/media',        [InboxController::class, 'mediaReply']);
    Route::patch('/inbox/{conversationId}/toggle-ai',   [InboxController::class, 'toggleAi']);
    Route::post('/messages/{messageId}/react',          [InboxController::class, 'reactToMessage']);

    // Reports
    Route::prefix('reports')->group(function () {
        Route::get('/daily-messages', [ReportsController::class, 'dailyMessages']);
        Route::get('/channel-breakdown', [ReportsController::class, 'channelBreakdown']);
        Route::get('/ai-performance', [ReportsController::class, 'aiPerformance']);
        Route::get('/top-questions', [ReportsController::class, 'topQuestions']);
        Route::get('/time-saved', [ReportsController::class, 'timeSaved']);
        Route::get('/summary', [ReportsController::class, 'summary']);
        Route::get('/export/csv', [ReportsController::class, 'exportCsv']);
        Route::get('/export/pdf', [ReportsController::class, 'exportPdf']);
    });

    // n8n Integration Routes
    Route::prefix('n8n')->group(function () {
        Route::post('/rate-limit/check', [N8nIntegrationController::class, 'checkRateLimit']);
        Route::post('/language/detect', [N8nIntegrationController::class, 'detectLanguage']);
        Route::post('/conversation/memory', [N8nIntegrationController::class, 'getConversationMemory']);
        Route::post('/ai/ultimate-process', [N8nIntegrationController::class, 'ultimateAIProcess']);
        Route::post('/escalate', [N8nIntegrationController::class, 'triggerEscalation']);
    });

    // Training & AI Learning
    Route::prefix('training')->group(function () {
        Route::get('/corrections', [TrainingController::class, 'getCorrections']);
        Route::post('/corrections', [TrainingController::class, 'createCorrection']);
        Route::post('/corrections/{id}/approve', [TrainingController::class, 'approveCorrection']);
        Route::delete('/corrections/{id}', [TrainingController::class, 'rejectCorrection']);
        Route::get('/stats', [TrainingController::class, 'getStats']);
    });

    // Proactive Campaigns
    Route::prefix('proactive')->group(function () {
        Route::get('/', [ProactiveController::class, 'index']);
        Route::post('/', [ProactiveController::class, 'store']);
        Route::post('/{id}/send', [ProactiveController::class, 'sendNow']);
        Route::post('/{id}/cancel', [ProactiveController::class, 'cancel']);
        Route::delete('/{id}', [ProactiveController::class, 'destroy']);
        Route::get('/{id}/stats', [ProactiveController::class, 'getStats']);
    });

    // Automation Workflows
    Route::prefix('automation')->group(function () {
        Route::get('/', [AutomationController::class, 'index']);
        Route::get('/templates', [AutomationController::class, 'getTemplates']);
        Route::post('/', [AutomationController::class, 'store']);
        Route::patch('/{id}', [AutomationController::class, 'update']);
        Route::delete('/{id}', [AutomationController::class, 'destroy']);
        Route::post('/{id}/test', [AutomationController::class, 'test']);
        Route::get('/{id}/stats', [AutomationController::class, 'getStats']);
    });

    // Top-level dashboard stats
    Route::get('/stats', [ReportsController::class, 'dashboardStats']);
});

// Gmail Webhook - public, Google Pub/Sub calls this
Route::post('/webhook/gmail', [WebhookController::class, 'handleGmail']);

// n8n Integration Routes (public with auth)
Route::prefix('n8n')->group(function () {
    Route::post('/rate-limit/check', [N8nIntegrationController::class, 'checkRateLimit']);
    Route::post('/language/detect', [N8nIntegrationController::class, 'detectLanguage']);
    Route::post('/conversation/memory', [N8nIntegrationController::class, 'getConversationMemory']);
    Route::post('/ai/ultimate-process', [N8nIntegrationController::class, 'ultimateAIProcess']);
    Route::post('/escalate', [N8nIntegrationController::class, 'triggerEscalation']);
});

// Public blog routes
Route::get('/posts', [PostController::class, 'index']);
Route::get('/posts/{slug}', [PostController::class, 'show']);

// Protected blog admin routes
Route::middleware(['auth:sanctum', 'admin'])->group(function () {
    Route::get('/admin/posts', [PostController::class, 'adminIndex']);
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

// Tools API (public with rate limiting)
Route::post('/tools/ai-call', [ToolsController::class, 'aiCall']);

// Moyasar webhook (public, exclude CSRF)
Route::post('/payments/webhook', [PaymentController::class, 'webhook'])->withoutMiddleware([\App\Http\Middleware\VerifyCsrfToken::class]);

// Protected subscription and payment routes
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/subscriptions/current', [SubscriptionController::class, 'current']);
    Route::post('/subscriptions/create-free', [SubscriptionController::class, 'createFree']);
    Route::delete('/subscriptions', [SubscriptionController::class, 'cancel']);
    Route::post('/payments/create', [PaymentController::class, 'createPayment']);

    // WhatsApp routes
    Route::prefix('whatsapp')->group(function () {
        Route::get('/status', [WhatsAppController::class, 'status']);
        Route::post('/connect', [WhatsAppController::class, 'connect']);
        Route::get('/qrcode', [WhatsAppController::class, 'getQrCode']);
        Route::post('/disconnect', [WhatsAppController::class, 'disconnect']);
        Route::post('/reconnect', [WhatsAppController::class, 'reconnect']);
        Route::post('/send', [WhatsAppController::class, 'sendMessage']);
        Route::get('/messages', [WhatsAppController::class, 'getMessages']);
        Route::get('/instance', [WhatsAppController::class, 'getInstance']);
    });
});

// Evolution API webhook (public, exclude CSRF)
Route::post('/whatsapp/webhook', [WhatsAppController::class, 'webhook'])
    ->withoutMiddleware([\App\Http\Middleware\VerifyCsrfToken::class])
    ->name('whatsapp.webhook');

// Admin routes (protected + admin middleware)
Route::middleware(['auth:sanctum', 'admin'])->prefix('admin')->group(function () {
    // Dashboard
    Route::get('/dashboard', [AdminController::class, 'dashboard']);
    
    // Users
    Route::get('/users', [AdminController::class, 'users']);
    Route::get('/users/{id}', [AdminController::class, 'showUser']);
    Route::patch('/users/{id}', [AdminController::class, 'updateUser']);
    Route::delete('/users/{id}', [AdminController::class, 'deleteUser']);
    Route::post('/users/{id}/toggle-admin', [AdminController::class, 'toggleAdmin']);
    
    // Packages
    Route::get('/packages', [AdminController::class, 'packages']);
    Route::get('/packages/{id}', [AdminController::class, 'showPackage']);
    Route::post('/packages', [AdminController::class, 'createPackage']);
    Route::patch('/packages/{id}', [AdminController::class, 'updatePackage']);
    Route::delete('/packages/{id}', [AdminController::class, 'deletePackage']);
    
    // Subscriptions/Payments
    Route::get('/subscriptions', [AdminController::class, 'subscriptions']);
    Route::get('/subscriptions/{id}', [AdminController::class, 'showSubscription']);
    Route::patch('/subscriptions/{id}', [AdminController::class, 'updateSubscription']);
    
    // Settings
    Route::get('/settings', [AdminController::class, 'settings']);
    Route::patch('/settings', [AdminController::class, 'updateSettings']);
});
