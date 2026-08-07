<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\N8nIntegrationController;

// n8n Integration Routes
Route::prefix('n8n')->group(function () {
    // Rate Limit Check
    Route::post('/rate-limit/check', [N8nIntegrationController::class, 'checkRateLimit']);
    
    // Language Detection
    Route::post('/language/detect', [N8nIntegrationController::class, 'detectLanguage']);
    
    // Conversation Memory
    Route::post('/conversation/memory', [N8nIntegrationController::class, 'getConversationMemory']);
    
    // Ultimate AI Process
    Route::post('/ai/ultimate-process', [N8nIntegrationController::class, 'ultimateAIProcess']);
    
    // Escalation
    Route::post('/escalate', [N8nIntegrationController::class, 'triggerEscalation']);
});
