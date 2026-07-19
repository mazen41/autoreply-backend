<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ValidateInput
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next)
    {
        $rules = $this->getValidationRules($request);

        if (!empty($rules)) {
            $validator = Validator::make($request->all(), $rules);

            if ($validator->fails()) {
                throw ValidationException::withMessages($validator->errors()->toArray());
            }
        }

        return $next($request);
    }

    /**
     * Get validation rules based on the request path and method.
     */
    private function getValidationRules(Request $request): array
    {
        $path = $request->path();
        $method = $request->method();

        $rules = [];

        // Auth routes
        if (str_starts_with($path, 'api/auth')) {
            if ($method === 'POST' && str_ends_with($path, 'register')) {
                $rules = [
                    'name' => 'required|string|max:255',
                    'email' => 'required|email|max:255|unique:users',
                    'password' => 'required|string|min:8|confirmed',
                ];
            }
            if ($method === 'POST' && str_ends_with($path, 'login')) {
                $rules = [
                    'email' => 'required|email',
                    'password' => 'required|string',
                ];
            }
            if ($method === 'POST' && str_ends_with($path, 'password')) {
                $rules = [
                    'current_password' => 'required|string',
                    'password' => 'required|string|min:8|confirmed',
                ];
            }
        }

        // Inbox routes
        if (str_starts_with($path, 'api/inbox')) {
            if ($method === 'POST' && str_ends_with($path, 'reply')) {
                $rules = [
                    'message' => 'required|string|max:5000',
                ];
            }
        }

        // Channels routes
        if (str_starts_with($path, 'api/channels')) {
            if ($method === 'PATCH') {
                $rules = [
                    'ai_enabled' => 'sometimes|boolean',
                    'status' => 'sometimes|string|in:connected,disconnected',
                ];
            }
        }

        // Onboarding routes
        if (str_starts_with($path, 'api/onboarding')) {
            $rules = [
                'step1' => [
                    'business_name' => 'required|string|max:255',
                    'business_type' => 'required|string|max:255',
                    'city' => 'required|string|max:255',
                    'country' => 'required|string|max:255',
                ],
                'step2' => [
                    'working_days' => 'required|array',
                    'working_from' => 'required|string',
                    'working_to' => 'required|string',
                ],
                'step3' => [
                    'services' => 'required|string',
                    'reply_style' => 'required|string',
                ],
                'step4' => [
                    'faqs' => 'sometimes|array',
                    'knowledge_base' => 'sometimes|string',
                ],
            ];

            foreach ($rules as $step => $stepRules) {
                if (str_ends_with($path, $step)) {
                    return $stepRules;
                }
            }
        }

        return $rules;
    }
}
