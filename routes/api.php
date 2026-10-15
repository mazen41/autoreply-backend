<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BusinessController;
use App\Http\Controllers\Api\OrdersController;
use App\Http\Controllers\Api\OnboardingController;
use App\Http\Controllers\Api\SocialAuthController;
use App\Http\Controllers\Api\PackageController;
use App\Http\Controllers\Api\SubscriptionController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\AdminController;
use App\Http\Controllers\Api\FeedbackController;
use App\Http\Controllers\Api\TeamController;
use App\Http\Controllers\Api\AutomationController;
use App\Http\Controllers\Api\CampaignController;
use App\Http\Controllers\Api\EmailCampaignController;
use App\Http\Controllers\Api\SequenceController;
use App\Http\Controllers\Api\WebhookController as ApiWebhookController;
use App\Http\Controllers\Api\PublicApiController;
use App\Http\Controllers\Api\AnalyticsController;
use App\Http\Controllers\Api\BillingController;
use App\Http\Controllers\Api\CsatController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\WebChatController;
use App\Http\Controllers\Api\AiActionsController;
use App\Http\Controllers\Api\AiReplyAuditController;
use App\Http\Controllers\Api\WhatsAppController;
use App\Http\Controllers\Api\WhatsAppCloudController;
use App\Http\Controllers\Api\MediaProcessingDisclosureController;
use App\Http\Controllers\Api\DataRightsController;
use App\Http\Controllers\Api\ReportsController;
use App\Http\Controllers\N8nIntegrationController;
use App\Http\Controllers\Api\KnowledgeController;
use App\Http\Controllers\Api\TrainingController;
use App\Http\Controllers\Api\ProactiveController;
use App\Http\Controllers\Api\ToolsController;
use App\Http\Controllers\Api\SallaWebhookController;
use App\Http\Controllers\Api\PusherAuthController;
use App\Http\Controllers\Api\MyFatoorahController;
use App\Http\Controllers\Api\MyFatoorahWebhookController;
use App\Http\Controllers\WebhookController as MetaWebhookController;
use App\Http\Controllers\ChannelController;
use App\Http\Controllers\GmailController;
use App\Http\Controllers\InboxController;
use App\Http\Controllers\PostController;
use App\Http\Controllers\TelegramController;
use App\Http\Controllers\PrivateMediaController;
use App\Http\Controllers\TikTokController;
use App\Http\Controllers\ShopifyController;
use App\Http\Controllers\WooCommerceController;
use Illuminate\Support\Facades\Route;

// ── Public auth routes ────────────────────────────────────────────────────────
Route::middleware('throttle:auth')->prefix('auth')->group(function () {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login',    [AuthController::class, 'login']);
    Route::post('/forgot-password', [AuthController::class, 'forgotPassword']);
    Route::post('/reset-password',  [AuthController::class, 'resetPassword']);
    Route::post('/verify-email',       [AuthController::class, 'verifyEmail']);
    Route::post('/resend-verification',[AuthController::class, 'resendVerification']);
    Route::post('/exchange', [\App\Http\Controllers\Api\SocialAuthController::class, 'exchangeLoginCode']);
    
    // Social login routes
    Route::get('/google/redirect',  [SocialAuthController::class, 'redirectToGoogle']);
    Route::get('/google/callback',  [SocialAuthController::class, 'handleGoogleCallback']);
    Route::get('/facebook/redirect', [SocialAuthController::class, 'redirectToFacebook']);
    Route::get('/facebook/callback', [SocialAuthController::class, 'handleFacebookCallback']);
});

// Pusher authentication routes
Route::middleware('auth:sanctum')->prefix('pusher')->group(function () {
    Route::post('/auth', [PusherAuthController::class, 'authenticate']);
    Route::get('/test', [PusherAuthController::class, 'testConnection']);
});

// Broadcasting authentication (for Laravel Echo)
Route::middleware('auth:sanctum')->prefix('broadcasting')->group(function () {
    Route::post('/auth', [PusherAuthController::class, 'authenticate']);
});

// ── Meta Webhook – public, Meta calls these directly ─────────────────────────
Route::get('/webhook/meta',  [MetaWebhookController::class, 'verify']);
Route::post('/webhook/meta', [MetaWebhookController::class, 'handle']);

// Short-lived signed URLs are used for private generated voice responses.
Route::get('/media/tts/{mediaPath}', [PrivateMediaController::class, 'showTts'])
    ->where('mediaPath', '.*')
    ->middleware('signed')
    ->name('media.tts.show');

// ── Public OAuth channels callbacks ──────────────────────────────────────────
Route::post('/channels/connect/facebook', [ChannelController::class, 'connectFacebook'])->middleware(['auth:sanctum', 'plan.enforce:max_channels']);
Route::get('/channels/callback/facebook', [ChannelController::class, 'callbackFacebook']);
Route::get('/channels/callback/gmail',    [GmailController::class, 'callback']);
Route::post('/channels/connect/salla',    [ChannelController::class, 'connectSalla'])->middleware(['auth:sanctum', 'plan.enforce:max_channels']);
Route::get('/channels/callback/salla',    [ChannelController::class, 'callbackSalla']);

// Invitation links are bearer-token capabilities. Throttle checks and account
// setup, and persist only the invitation token hash.
Route::get('/team-invitations/check/{token}', [TeamController::class, 'checkPublicInvitation'])
    ->where('token', '[A-Za-z0-9]{64}')
    ->middleware('throttle:30,1');
Route::post('/team-invitations/{token}/accept', [TeamController::class, 'acceptPublicInvitation'])
    ->where('token', '[A-Za-z0-9]{64}')
    ->middleware('throttle:10,1');
Route::post('/team-invitations/{token}/reject', [TeamController::class, 'rejectPublicInvitation'])
    ->where('token', '[A-Za-z0-9]{64}')
    ->middleware('throttle:10,1');

// New channel OAuth endpoints
Route::post('/channels/connect/tiktok',   [TikTokController::class, 'connect'])->middleware(['auth:sanctum', 'plan.enforce:max_channels']);
Route::get('/channels/callback/tiktok',    [TikTokController::class, 'callback']);
Route::get('/channels/callback/shopify',   [ShopifyController::class, 'callback']);

// Webhook endpoints (public)
// New, exact-account URL: identifies the precise Channel that registered the
// webhook, so multiple Telegram bots for the same user route correctly.
Route::post('/telegram/webhook/{userId}/{channelId}', [TelegramController::class, 'webhook']);
// Legacy URL kept for bots registered before this fix, until they are
// re-synced (see php artisan telegram:resync-webhooks). Falls back to
// "first connected channel" ONLY when unambiguous — see webhook() below.
Route::post('/telegram/webhook/{userId}',  [TelegramController::class, 'webhook']);
Route::post('/tiktok/webhook',             [TikTokController::class, 'webhook']);
Route::post('/shopify/webhook',            [ShopifyController::class, 'webhook']);
Route::post('/woocommerce/webhook', [WooCommerceController::class, 'webhook']);
Route::post('/channels/woocommerce/callback', [WooCommerceController::class, 'authorizationCallback']);

// Salla Webhook - public, Salla calls these directly
Route::post('/salla/webhook', [SallaWebhookController::class, 'handle']);

// ── MyFatoorah Webhook (public – MyFatoorah POSTs here) ──────────────────────
Route::post('/webhooks/myfatoorah', [MyFatoorahWebhookController::class, 'handle'])
    ->name('webhooks.myfatoorah');

// Email campaign open-tracking pixel — public, hit by recipients' mail clients
Route::get('/email-campaigns/track/open/{recipientId}', [EmailCampaignController::class, 'trackOpen']);
Route::get('/email-campaigns/track/click/{recipientId}/{linkToken}', [EmailCampaignController::class, 'trackClick']);
Route::match(['get', 'post'], '/email-campaigns/unsubscribe/{trackingToken}', [EmailCampaignController::class, 'unsubscribe'])
    ->where('trackingToken', '[A-Za-z0-9]{48}');

// ── Protected routes ──────────────────────────────────────────────────────────
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/businesses', [BusinessController::class, 'index']);
    Route::get('/business', [BusinessController::class, 'showProfile']);
    Route::post('/business', [BusinessController::class, 'updateProfile']);
    Route::get('/orders', [OrdersController::class, 'index']);
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/auth/user',    [AuthController::class, 'user']);
    Route::patch('/auth/profile', [AuthController::class, 'updateProfile']);
    Route::post('/auth/password', [AuthController::class, 'changePassword']);
    Route::get('/businesses/{businessId}/go-live-settings', [BusinessController::class, 'showGoLiveSettings']);
    Route::put('/businesses/{businessId}/go-live-settings', [BusinessController::class, 'updateGoLiveSettings']);

    // Business-scoped privacy, data export, and retention controls.
    Route::get('/businesses/{businessId}/data-retention', [DataRightsController::class, 'retention']);
    Route::put('/businesses/{businessId}/data-retention', [DataRightsController::class, 'updateRetention']);
    Route::get('/businesses/{businessId}/data-rights-requests', [DataRightsController::class, 'requests']);
    Route::get('/businesses/{businessId}/data-rights/customers', [DataRightsController::class, 'customers']);
    Route::post('/businesses/{businessId}/data-exports', [DataRightsController::class, 'requestExport']);
    Route::get('/businesses/{businessId}/data-exports/{requestId}/download', [DataRightsController::class, 'downloadExport']);
    Route::delete('/businesses/{businessId}/customers/{customerId}/data', [DataRightsController::class, 'eraseCustomer']);

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
    Route::get('/ai/setup-verification', [\App\Http\Controllers\Api\AiSetupVerificationController::class, 'show']);
    Route::get('/media-processing/disclosure', [MediaProcessingDisclosureController::class, 'show']);
    Route::put('/media-processing/disclosure', [MediaProcessingDisclosureController::class, 'update']);
    Route::post('/multimodal/test-image', [\App\Http\Controllers\Api\MultimodalController::class, 'testImage'])
        ->middleware('throttle:10,1');
    Route::prefix('knowledge')->group(function () {
        Route::get('/', [KnowledgeController::class, 'index']);
        Route::post('/upload', [KnowledgeController::class, 'upload']);
        Route::delete('/files/{id}', [KnowledgeController::class, 'delete']);
        Route::post('/instructions', [KnowledgeController::class, 'updateInstructions']);
        Route::post('/profile', [KnowledgeController::class, 'updateProfile']);
        Route::post('/test', [KnowledgeController::class, 'testResponse']);
        Route::post('/reindex', [KnowledgeController::class, 'reindex']);
        Route::post('/search', [KnowledgeController::class, 'search']);
    });

    // Bot Management
    Route::apiResource('bots', \App\Http\Controllers\Api\BotController::class)->except(['store']);
    Route::post('/bots', [\App\Http\Controllers\Api\BotController::class, 'store'])->middleware('plan.enforce:max_bots');
    // Bot-Channel management
    Route::get('/bots/{id}/channels', [\App\Http\Controllers\Api\BotController::class, 'channels']);
    Route::post('/bots/{id}/channels', [\App\Http\Controllers\Api\BotController::class, 'attachChannels']);
    Route::delete('/bots/{id}/channels/{channelId}', [\App\Http\Controllers\Api\BotController::class, 'detachChannel']);
    // Bot-Knowledge assignment management
    Route::get('/bots/{id}/knowledge', [\App\Http\Controllers\Api\BotController::class, 'knowledge']);
    Route::post('/bots/{id}/knowledge', [\App\Http\Controllers\Api\BotController::class, 'assignKnowledge']);
    Route::delete('/bots/{id}/knowledge/{fileId}', [\App\Http\Controllers\Api\BotController::class, 'removeKnowledge']);
    Route::get('/bots/{botId}/comment-automation', [\App\Http\Controllers\Api\CommentAutomationSettingsController::class, 'show']);
    Route::put('/bots/{botId}/comment-automation', [\App\Http\Controllers\Api\CommentAutomationSettingsController::class, 'update']);

    // Customer / CRM
    Route::get('/customers', [\App\Http\Controllers\Api\CustomerController::class, 'index']);
    Route::get('/customers/{id}', [\App\Http\Controllers\Api\CustomerController::class, 'show']);
    Route::post('/customers/{customerId}/notes', [\App\Http\Controllers\Api\CustomerController::class, 'storeNote']);
    Route::patch('/customers/{customerId}/tags', [\App\Http\Controllers\Api\CustomerController::class, 'updateTags']);

    // Channels – listing and disconnect
    Route::get('/channels/connect/gmail',     [GmailController::class, 'connect'])->middleware('plan.enforce:max_channels');
    Route::get('/channels/gmail/fetch',         [GmailController::class, 'fetchEmails']);
    Route::get('/channels',                   [ChannelController::class, 'index']);
    Route::patch('/channels/{id}',            [ChannelController::class, 'update']);
    Route::delete('/channels/{id}',           [ChannelController::class, 'disconnect']);

    // Public API key management (secret value is shown once at creation).
    Route::get('/api-keys', [\App\Http\Controllers\Api\ApiKeyController::class, 'index']);
    Route::post('/api-keys', [\App\Http\Controllers\Api\ApiKeyController::class, 'store']);
    Route::delete('/api-keys/{id}', [\App\Http\Controllers\Api\ApiKeyController::class, 'destroy']);
    Route::post('/api-keys/{id}/toggle', [\App\Http\Controllers\Api\ApiKeyController::class, 'toggle']);

    // Business-scoped campaign audiences and reusable message templates.
    Route::get('/segments', [\App\Http\Controllers\Api\CampaignAudienceController::class, 'segments']);
    Route::post('/segments', [\App\Http\Controllers\Api\CampaignAudienceController::class, 'storeSegment']);
    Route::put('/segments/{id}', [\App\Http\Controllers\Api\CampaignAudienceController::class, 'updateSegment']);
    Route::delete('/segments/{id}', [\App\Http\Controllers\Api\CampaignAudienceController::class, 'destroySegment']);
    Route::get('/campaign-templates', [\App\Http\Controllers\Api\CampaignAudienceController::class, 'templates']);
    Route::post('/campaign-templates', [\App\Http\Controllers\Api\CampaignAudienceController::class, 'storeTemplate']);
    Route::put('/campaign-templates/{id}', [\App\Http\Controllers\Api\CampaignAudienceController::class, 'updateTemplate']);
    Route::delete('/campaign-templates/{id}', [\App\Http\Controllers\Api\CampaignAudienceController::class, 'destroyTemplate']);
    
    Route::post('/channels/salla/claim', [ChannelController::class, 'claimSalla'])->middleware('plan.enforce:max_channels');
    // New channel endpoints (protected)
    Route::post('/channels/telegram/connect', [TelegramController::class, 'connect'])->middleware('plan.enforce:max_channels');
    Route::post('/channels/telegram/set-webhook', [TelegramController::class, 'setWebhook']);
    Route::post('/channels/telegram/disconnect', [TelegramController::class, 'disconnect']);
    Route::post('/channels/shopify/connect', [ShopifyController::class, 'connect'])->middleware('plan.enforce:max_channels');
    Route::post('/channels/shopify/{channelId}/sync', [ShopifyController::class, 'sync']);
    Route::post('/channels/woocommerce/connect', [WooCommerceController::class, 'connect'])->middleware('plan.enforce:max_channels');
    Route::get('/channels/woocommerce/connection-status', [WooCommerceController::class, 'connectionStatus']);
    Route::post('/channels/woocommerce/{channelId}/sync', [WooCommerceController::class, 'sync']);
    Route::post('/channels/whatsapp/cloud', [WhatsAppCloudController::class, 'connect'])->middleware('plan.enforce:max_channels');
    Route::get('/channels/whatsapp/{channelId}/templates', [WhatsAppCloudController::class, 'templates']);
    Route::post('/channels/whatsapp/{channelId}/templates', [WhatsAppCloudController::class, 'createTemplate']);
    Route::post('/channels/whatsapp/{channelId}/accept-unofficial-risk', [WhatsAppCloudController::class, 'acceptUnofficialRisk']);
    Route::get('/channels/shopify/orders',    [ShopifyController::class, 'getOrders']);
    Route::get('/channels/woocommerce/orders', [WooCommerceController::class, 'getOrders']);
    
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
    Route::get('/handoff-tasks', [\App\Http\Controllers\Api\HandoffTaskController::class, 'index']);
    Route::get('/handoff-tasks/assignees', [\App\Http\Controllers\Api\HandoffTaskController::class, 'assignees']);
    Route::patch('/handoff-tasks/{taskId}', [\App\Http\Controllers\Api\HandoffTaskController::class, 'update']);
    Route::patch('/handoff-tasks/{taskId}/resolve', [\App\Http\Controllers\Api\HandoffTaskController::class, 'resolve']);
    Route::patch('/handoff-tasks/{taskId}/assign', [\App\Http\Controllers\Api\HandoffTaskController::class, 'assign']);
    Route::get('/inbox',                                [InboxController::class, 'index']);
    Route::get('/social-comments', [\App\Http\Controllers\Api\SocialCommentController::class, 'index']);
    Route::post('/social-comments/simulate', [\App\Http\Controllers\Api\SocialCommentController::class, 'simulate']);
    Route::get('/social-comments/scenarios', [\App\Http\Controllers\Api\SocialCommentController::class, 'scenarios']);
    Route::post('/social-comments/scenarios', [\App\Http\Controllers\Api\SocialCommentController::class, 'storeScenario']);
    Route::post('/social-comments/scenarios/run', [\App\Http\Controllers\Api\SocialCommentController::class, 'runScenarios']);
    Route::delete('/social-comments/scenarios/{scenarioId}', [\App\Http\Controllers\Api\SocialCommentController::class, 'deleteScenario']);
    Route::get('/inbox/{conversationId}/messages',      [InboxController::class, 'messages']);
    Route::post('/businesses/{businessId}/conversations/{conversationId}/read', [InboxController::class, 'markRead'])
        ->middleware('business.access:conversation_read');
    Route::get('/messages/{messageId}/ai-audit', [AiReplyAuditController::class, 'show']);
    Route::get('/messages/{messageId}/media',           [InboxController::class, 'media']);
    Route::post('/inbox/{conversationId}/reply',        [InboxController::class, 'reply']);
    Route::post('/inbox/{conversationId}/media',        [InboxController::class, 'mediaReply']);
    Route::patch('/inbox/{conversationId}/toggle-ai',   [InboxController::class, 'toggleAi']);
    Route::patch('/inbox/{conversationId}/status',      [InboxController::class, 'updateStatus']);
    Route::patch('/conversations/{id}/bot',             [InboxController::class, 'updateBot']);
    Route::post('/messages/{messageId}/react',          [InboxController::class, 'reactToMessage']);
    
    // Tag management
    Route::get('/inbox/{conversationId}/tags',         [InboxController::class, 'getTags']);
    Route::post('/inbox/{conversationId}/tags',        [InboxController::class, 'addTag']);
    Route::delete('/inbox/{conversationId}/tags/{tagId}', [InboxController::class, 'removeTag']);
    Route::get('/tags/all',                             [InboxController::class, 'getAllTags']);

    // AI Feedback
    Route::post('/messages/{messageId}/feedback',     [FeedbackController::class, 'submit']);
    Route::get('/feedback/statistics',                [FeedbackController::class, 'statistics']);
    Route::get('/feedback/recent',                     [FeedbackController::class, 'recent']);

    // Team Management
    Route::middleware('business.access:manage_team')->group(function () {
          Route::get('/businesses/{businessId}/team', [TeamController::class, 'index']);
          Route::get('/businesses/{businessId}/team/invitations', [TeamController::class, 'pendingInvitations']);
        Route::post('/businesses/{businessId}/team/invite', [TeamController::class, 'invite'])->middleware('plan.enforce:max_team_members');
        Route::post('/businesses/{businessId}/team/invitations/{invitationId}/resend', [TeamController::class, 'resendInvitation']);
        Route::delete('/businesses/{businessId}/team/invitations/{invitationId}', [TeamController::class, 'revokeInvitation']);
        Route::patch('/businesses/{businessId}/team/{memberId}/role', [TeamController::class, 'updateRole']);
        Route::delete('/businesses/{businessId}/team/{memberId}', [TeamController::class, 'remove']);
    });
    Route::post('/conversations/{conversationId}/assign', [TeamController::class, 'assignConversation']);
    Route::get('/team/assignments',                    [TeamController::class, 'myAssignments']);
      Route::post('/team/invitations/accept', [TeamController::class, 'acceptInvitation']);
      Route::get('/team/invitations', [TeamController::class, 'myPendingInvitations']);
      Route::post('/team/invitations/{invitationId}/accept', [TeamController::class, 'acceptInvitationById']);

    // Automation
    Route::get('/businesses/{businessId}/automation-run-control', [\App\Http\Controllers\Api\AutomationRunControlController::class, 'show'])
        ->middleware('business.access:automation_read');
    Route::put('/businesses/{businessId}/automation-run-control', [\App\Http\Controllers\Api\AutomationRunControlController::class, 'update'])
        ->middleware('business.access:automation_write');
    Route::get('/businesses/{businessId}/rollout-flags', [\App\Http\Controllers\Api\BusinessFeatureFlagController::class, 'index'])
        ->middleware('business.access:automation_read');
    Route::put('/businesses/{businessId}/rollout-flags/{key}', [\App\Http\Controllers\Api\BusinessFeatureFlagController::class, 'update'])
        ->middleware('business.access:automation_write');
    Route::middleware('business.access:automation_read')->group(function () {
        Route::get('/businesses/{businessId}/hours', [AutomationController::class, 'getBusinessHours']);
        Route::get('/businesses/{businessId}/auto-messages', [AutomationController::class, 'getAutoMessages']);
        Route::get('/automation/comment-settings/{businessId}', [AutomationController::class, 'getCommentSettings']);
    });
    Route::middleware('business.access:automation_write')->group(function () {
        Route::put('/businesses/{businessId}/hours', [AutomationController::class, 'updateBusinessHours']);
        Route::put('/businesses/{businessId}/auto-messages', [AutomationController::class, 'updateAutoMessage']);
        Route::put('/businesses/{businessId}/timezone', [AutomationController::class, 'updateTimezone']);
        Route::post('/automation/comment-settings/{businessId}', [AutomationController::class, 'updateCommentSettings']);
    });

    // Campaigns
    Route::middleware('business.access:read')->group(function () {
        Route::get('/businesses/{businessId}/campaigns', [CampaignController::class, 'index']);
        Route::get('/businesses/{businessId}/campaigns/{campaignId}/logs', [CampaignController::class, 'logs']);
        Route::post('/businesses/{businessId}/campaigns/audience/preview', [CampaignController::class, 'audiencePreview']);
    });
    Route::middleware('business.access:write')->group(function () {
        Route::post('/businesses/{businessId}/campaigns', [CampaignController::class, 'store']);
        Route::put('/businesses/{businessId}/campaigns/{campaignId}', [CampaignController::class, 'update']);
        Route::delete('/businesses/{businessId}/campaigns/{campaignId}', [CampaignController::class, 'destroy']);
        Route::post('/businesses/{businessId}/campaigns/{campaignId}/launch', [CampaignController::class, 'launch']);
        Route::post('/businesses/{businessId}/campaigns/{campaignId}/cancel-schedule', [CampaignController::class, 'cancelSchedule']);
        Route::post('/businesses/{businessId}/campaigns/{campaignId}/pause', [CampaignController::class, 'pause']);
        Route::post('/businesses/{businessId}/campaigns/{campaignId}/resume', [CampaignController::class, 'resume']);
    });

    // Email Campaigns
    Route::get('/email-campaigns',              [EmailCampaignController::class, 'index']);
    Route::post('/email-campaigns',             [EmailCampaignController::class, 'store']);
    Route::put('/email-campaigns/{id}',         [EmailCampaignController::class, 'update']);
    Route::patch('/email-campaigns/{id}',       [EmailCampaignController::class, 'update']);
    Route::post('/email-campaigns/{id}/send',   [EmailCampaignController::class, 'send']);
    Route::post('/email-campaigns/{id}/pause', [EmailCampaignController::class, 'pause'])->middleware('business.access:automation_write');
    Route::post('/email-campaigns/{id}/resume', [EmailCampaignController::class, 'resume'])->middleware('business.access:automation_write');
    Route::post('/email-campaigns/{id}/schedule', [EmailCampaignController::class, 'schedule']);
    Route::post('/email-campaigns/{id}/cancel-schedule', [EmailCampaignController::class, 'cancelSchedule']);
    Route::get('/email-campaigns/{id}/stats',   [EmailCampaignController::class, 'stats']);
    Route::delete('/email-campaigns/{id}',      [EmailCampaignController::class, 'destroy']);
    Route::get('/email-campaigns/audience/channels',  [EmailCampaignController::class, 'audienceChannels']);
    Route::post('/email-campaigns/audience/preview',  [EmailCampaignController::class, 'audiencePreview']);

    // Sequences
    Route::get('/sequences', [SequenceController::class, 'index']);
    Route::post('/sequences', [SequenceController::class, 'store']);
    Route::get('/sequences/{id}', [SequenceController::class, 'show']);
    Route::put('/sequences/{id}', [SequenceController::class, 'update']);
    Route::delete('/sequences/{id}', [SequenceController::class, 'destroy']);
    Route::post('/sequences/{id}/activate', [SequenceController::class, 'activate']);
    Route::post('/sequences/{id}/pause', [SequenceController::class, 'pause']);
    Route::post('/sequences/{id}/duplicate', [SequenceController::class, 'duplicate']);
    Route::get('/sequences/{id}/analytics', [SequenceController::class, 'analytics']);
    Route::get('/sequences/{id}/enrollments', [SequenceController::class, 'enrollments']);
    Route::post('/sequences/{id}/enroll', [SequenceController::class, 'enroll']);
    Route::post('/sequences/{id}/unenroll', [SequenceController::class, 'unenroll']);
    Route::get('/sequences/queue/metrics', [SequenceController::class, 'queueMetrics']);
    Route::post('/sequences/queue/reset-stuck', [SequenceController::class, 'resetStuckExecutions']);

    // Webhooks
    Route::middleware('business.access:read')->group(function () {
        Route::get('/businesses/{businessId}/webhooks', [ApiWebhookController::class, 'index']);
    });
    Route::middleware('business.access:write')->group(function () {
        Route::post('/businesses/{businessId}/webhooks', [ApiWebhookController::class, 'store']);
        Route::put('/businesses/{businessId}/webhooks/{webhookId}', [ApiWebhookController::class, 'update']);
        Route::delete('/businesses/{businessId}/webhooks/{webhookId}', [ApiWebhookController::class, 'destroy']);
        Route::post('/businesses/{businessId}/webhooks/{webhookId}/test', [ApiWebhookController::class, 'test']);
        Route::post('/businesses/{businessId}/webhooks/{webhookId}/rotate-secret', [ApiWebhookController::class, 'rotateSecret']);
    });

    // Analytics
    Route::middleware('business.access:read')->group(function () {
        Route::get('/businesses/{businessId}/analytics/dashboard', [AnalyticsController::class, 'getDashboard']);
        Route::get('/businesses/{businessId}/analytics/csat', [AnalyticsController::class, 'getCsatScore']);
        Route::get('/businesses/{businessId}/analytics/daily', [AnalyticsController::class, 'getDailyAnalytics']);
        Route::get('/businesses/{businessId}/analytics/ai-metrics', [AnalyticsController::class, 'getAiMetrics']);
        Route::get('/businesses/{businessId}/analytics/ratings', [AnalyticsController::class, 'getRecentRatings']);
        Route::get('/businesses/{businessId}/usage', [BillingController::class, 'usageForBusiness']);
    });
    Route::post('/businesses/{businessId}/analytics/calculate', [AnalyticsController::class, 'calculateAnalytics'])->middleware('business.access:write');

    // CSAT Ratings
    Route::post('/conversations/{conversationId}/csat', [CsatController::class, 'submitRating']);
    Route::get('/conversations/{conversationId}/csat', [CsatController::class, 'getRating']);

    // Billing
    Route::middleware('business.access:read')->group(function () {
        Route::get('/billing/subscription', [BillingController::class, 'getCurrentSubscription']);
        Route::get('/billing/usage', [BillingController::class, 'getUsageStats']);
        Route::get('/billing/service-status', [BillingController::class, 'checkServiceStatus']);
        Route::get('/billing/history', [BillingController::class, 'getBillingHistory']);
        Route::get('/subscriptions/current', [SubscriptionController::class, 'current']);
    });
    Route::middleware('business.access:manage_billing')->group(function () {
        Route::post('/billing/upgrade', [BillingController::class, 'upgradePlan']);
        Route::post('/subscriptions/create-free', [SubscriptionController::class, 'createFree']);
        Route::delete('/subscriptions', [SubscriptionController::class, 'cancel']);
        Route::post('/subscriptions/auto-renew/disable', [SubscriptionController::class, 'disableAutoRenew']);
        Route::post('/payments/create', [PaymentController::class, 'createPayment']);
        Route::post('/payment/myfatoorah/initiate', [MyFatoorahController::class, 'initiate'])
            ->name('payment.myfatoorah.initiate');
    });

    // Notifications
    Route::get('/notifications', [NotificationController::class, 'index']);
    Route::get('/notifications/unread-count', [NotificationController::class, 'unreadCount']);
    Route::post('/notifications/{notificationId}/read', [NotificationController::class, 'markAsRead']);
    Route::post('/notifications/mark-all-read', [NotificationController::class, 'markAllAsRead']);
    Route::delete('/notifications/{notificationId}', [NotificationController::class, 'destroy']);

    // Onboarding
    Route::get('/onboarding/status', [OnboardingController::class, 'getStatus']);
    Route::post('/onboarding/progress', [OnboardingController::class, 'updateProgress']);
    Route::post('/onboarding/complete-step', [OnboardingController::class, 'completeStep']);
    Route::post('/onboarding/skip', [OnboardingController::class, 'skip']);
    Route::post('/onboarding/initialize', [OnboardingController::class, 'initialize']);

    // Web Chat
    Route::prefix('web-chat')->group(function () {
        Route::post('/sessions', [WebChatController::class, 'createSession']);
        Route::post('/messages', [WebChatController::class, 'sendMessage']);
        Route::get('/sessions/{sessionId}/messages', [WebChatController::class, 'getMessages']);
        Route::post('/sessions/{sessionId}/status', [WebChatController::class, 'updateOnlineStatus']);
    });

    // AI Actions
    Route::middleware('business.access:read')->group(function () {
        Route::get('/businesses/{businessId}/ai-actions', [AiActionsController::class, 'index']);
        Route::get('/businesses/{businessId}/ai-actions/pending', [AiActionsController::class, 'pending']);
        Route::get('/businesses/{businessId}/ai-actions/statistics', [AiActionsController::class, 'statistics']);
    });
    Route::middleware('business.access:write')->group(function () {
        Route::post('/businesses/{businessId}/ai-actions/{actionId}/approve', [AiActionsController::class, 'approve']);
        Route::post('/businesses/{businessId}/ai-actions/{actionId}/reject', [AiActionsController::class, 'reject']);
    });

    // Reports
    Route::prefix('reports')->group(function () {
        Route::get('/daily-messages', [ReportsController::class, 'dailyMessages']);
        Route::get('/channel-breakdown', [ReportsController::class, 'channelBreakdown']);
        Route::get('/ai-performance', [ReportsController::class, 'aiPerformance']);
        Route::get('/top-questions', [ReportsController::class, 'topQuestions']);
        Route::get('/time-saved', [ReportsController::class, 'timeSaved']);
        Route::put('/time-saved/settings', [ReportsController::class, 'updateTimeSavedSettings']);
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

    // Automation Workflows (canonical /api/workflows)
    Route::prefix('workflows')->group(function () {
        Route::get('/', [AutomationController::class, 'index']);
        Route::get('/templates', [AutomationController::class, 'getTemplates']);
        Route::post('/', [AutomationController::class, 'store'])->middleware('feature.flag:advanced_automation');
        Route::get('/{id}', [AutomationController::class, 'show']);
        Route::patch('/{id}', [AutomationController::class, 'update'])->middleware('feature.flag:advanced_automation');
        Route::delete('/{id}', [AutomationController::class, 'destroy']);
        Route::post('/{id}/toggle', [AutomationController::class, 'toggle']);
        Route::post('/{id}/duplicate', [AutomationController::class, 'duplicate']);
        Route::post('/{id}/test', [AutomationController::class, 'test']);
        Route::get('/{id}/executions', [AutomationController::class, 'executions']);
        Route::post('/{id}/executions/{executionId}/replay', [AutomationController::class, 'replayExecution'])->middleware('feature.flag:advanced_automation');
        Route::get('/{id}/stats', [AutomationController::class, 'getStats']);
    });

    // Top-level dashboard stats
    Route::get('/stats', [ReportsController::class, 'dashboardStats']);

    // ── MyFatoorah Payment (authenticated) ───────────────────────────────────
    Route::get('/payment/callback', [MyFatoorahController::class, 'callback'])
        ->name('payment.callback');
    Route::get('/payment/error', [MyFatoorahController::class, 'error'])
        ->name('payment.error');
});

// Gmail Webhook - public, Google Pub/Sub calls this
Route::post('/webhook/gmail', [MetaWebhookController::class, 'handleGmail']);

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

// Public API (with API key authentication)
Route::prefix('public')->group(function () {
    Route::post('/messages/send', [PublicApiController::class, 'sendMessage']);
    Route::get('/conversations', [PublicApiController::class, 'getConversations']);
    Route::get('/conversations/{conversationId}/messages', [PublicApiController::class, 'getMessages']);
});

// Public package routes
Route::get('/packages', [PackageController::class, 'index']);
Route::get('/packages/{id}', [PackageController::class, 'show']);

// Payment callback (public - Paymob redirects here after checkout)
Route::get('/payments/callback', [PaymentController::class, 'callback']);

// Tools API (public with rate limiting)
Route::post('/tools/ai-call', [ToolsController::class, 'aiCall']);

// Paymob webhook (public, exclude CSRF)
Route::post('/payments/webhook', [PaymentController::class, 'webhook'])->withoutMiddleware([\App\Http\Middleware\VerifyCsrfToken::class]);

// Protected subscription and payment routes
Route::middleware('auth:sanctum')->group(function () {
    // WhatsApp routes
    Route::prefix('whatsapp')->group(function () {
        Route::get('/status', [WhatsAppController::class, 'status']);
        Route::post('/connect', [WhatsAppController::class, 'connect'])->middleware('plan.enforce:max_channels');
        Route::get('/qrcode', [WhatsAppController::class, 'getQrCode']);
        Route::post('/disconnect', [WhatsAppController::class, 'disconnect']);
        Route::post('/reconnect', [WhatsAppController::class, 'reconnect'])->middleware('plan.enforce:max_channels');
        Route::post('/send', [WhatsAppController::class, 'sendMessage'])
            ->middleware(['business.access:write', 'plan.enforce:max_monthly_messages']);
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

    // Queue Management
    Route::get('/queue/stats', [AdminController::class, 'queueStats']);
    Route::post('/queue/retry/{id}', [AdminController::class, 'retryFailedJob']);
    Route::delete('/queue/failed/{id}', [AdminController::class, 'deleteFailedJob']);

    // System Logs
    Route::get('/logs', [AdminController::class, 'systemLogs']);

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
