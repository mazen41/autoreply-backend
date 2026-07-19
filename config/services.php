<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_LOGIN_REDIRECT_URI'),
    ],

    'facebook' => [
        'client_id' => env('FACEBOOK_CLIENT_ID'),
        'client_secret' => env('FACEBOOK_CLIENT_SECRET'),
        'redirect' => env('FACEBOOK_LOGIN_REDIRECT_URI'),
    ],

    'meta' => [
        'webhook_verify_token' => env('META_WEBHOOK_VERIFY_TOKEN'),
    ],

    'evolution' => [
        'base_url' => env('EVOLUTION_BASE_URL', 'http://localhost:8080'),
        'api_key' => env('EVOLUTION_API_KEY', ''),
        'timeout' => env('EVOLUTION_TIMEOUT', 30),
        'max_retries' => env('EVOLUTION_MAX_RETRIES', 3),
        'media_disk' => env('EVOLUTION_MEDIA_DISK', 'public'),
    ],

    'moyasar' => [
        'publishable_key' => env('MOYASAR_PUBLISHABLE_KEY'),
        'secret_key' => env('MOYASAR_SECRET_KEY'),
        'webhook_secret' => env('MOYASAR_WEBHOOK_SECRET'),
    ],

    'ai' => [
        'provider' => env('AI_PROVIDER', 'gemini'),
        'fallback_provider' => env('AI_FALLBACK_PROVIDER', 'claude'),
        'temperature' => (float) env('AI_TEMPERATURE', 0.7),
        'max_tokens' => (int) env('AI_MAX_TOKENS', 500),
        'timeout' => (int) env('AI_TIMEOUT', 30),
        'retries' => (int) env('AI_RETRIES', 3),
        'streaming' => filter_var(env('AI_STREAMING', false), FILTER_VALIDATE_BOOLEAN),
    ],

    'gemini' => [
        'api_key' => env('GEMINI_API_KEY'),
        'model' => env('GEMINI_MODEL', 'gemini-2.5-flash'),
    ],

    'claude' => [
        'api_key' => env('ANTHROPIC_API_KEY'),
        'model' => env('CLAUDE_MODEL', 'claude-haiku-4-5-20251001'),
    ],
    'frontend_url' => env('FRONTEND_URL'),

];

