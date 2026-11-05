<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\InsufficientAiCreditsException;
use App\Http\Controllers\Controller;
use App\Services\ActiveBusiness;
use App\Services\AiGateway;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ToolsController extends Controller
{
    public function aiCall(Request $request, AiGateway $gateway)
    {
        $validated = $request->validate([
            'system_prompt' => 'required|string|max:30000',
            'user_message' => 'required|string|max:30000',
        ]);
        $business = app(ActiveBusiness::class)->resolve($request, permission: 'read');
        $idempotencyKey = (string) ($request->header('Idempotency-Key') ?: Str::uuid());

        try {
            $result = $gateway->chat(
                $business,
                'ai_assistant',
                $validated['system_prompt'],
                [['role' => 'user', 'content' => $validated['user_message']]],
                (int) config('services.ai.max_tokens', 2048),
                'tools-ai-call:' . $business->id . ':' . $idempotencyKey,
                ['user_id' => $request->user()?->id],
            );
            return response()->json(['result' => $result['content'] ?? '']);
        } catch (InsufficientAiCreditsException $exception) {
            return response()->json(['error' => 'INSUFFICIENT_AI_CREDITS', 'message' => 'This workspace has insufficient AI credits.'], 402);
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            report($exception);
            return response()->json(['error' => 'AI_REQUEST_FAILED', 'message' => 'The AI request could not be completed. Please try again later.'], 502);
        }
    }
}
