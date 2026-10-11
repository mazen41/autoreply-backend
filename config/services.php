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
        'redirect' => env('GOOGLE_LOGIN_REDIRECT_URI', env('GOOGLE_REDIRECT_URI')),
    ],

    'shopify' => [
        'client_id' => env('SHOPIFY_CLIENT_ID', env('SHOPIFY_API_KEY')),
        'client_secret' => env('SHOPIFY_CLIENT_SECRET', env('SHOPIFY_API_SECRET')),
        'redirect' => env('SHOPIFY_REDIRECT_URI'),
        'scopes' => env('SHOPIFY_SCOPES', 'read_products,read_inventory,read_orders,read_customers,write_orders,write_customers,write_webhooks'),
        'api_version' => env('SHOPIFY_API_VERSION', '2026-07'),
        'use_legacy_install_flow' => filter_var(env('SHOPIFY_USE_LEGACY_INSTALL_FLOW', false), FILTER_VALIDATE_BOOLEAN),
    ],

    'facebook' => [
        'client_id' => env('FACEBOOK_CLIENT_ID'),
        'client_secret' => env('FACEBOOK_CLIENT_SECRET'),
        'redirect' => env('FACEBOOK_LOGIN_REDIRECT_URI'),
    ],

    'meta' => [
        'app_secret' => env('META_APP_SECRET'),
        'webhook_verify_token' => env('META_WEBHOOK_VERIFY_TOKEN'),
        'graph_version' => env('META_GRAPH_API_VERSION', 'v26.0'),
    ],

    'nominatim' => [
        // Configure a private/self-hosted Nominatim endpoint. Do not send
        // customer delivery addresses to the public Nominatim service.
        'endpoint' => env('NOMINATIM_BASE_URL'),
    ],

    'evolution' => [
        'base_url' => env('EVOLUTION_BASE_URL', 'http://localhost:8080'),
        'api_key' => env('EVOLUTION_API_KEY', ''),
        'timeout' => env('EVOLUTION_TIMEOUT', 30),
        'max_retries' => env('EVOLUTION_MAX_RETRIES', 3),
        'media_disk' => env('EVOLUTION_MEDIA_DISK', 'public'),
        'incoming_media_disk' => env('INCOMING_MEDIA_DISK', 'private_media'),
        'incoming_media_retention_days' => (int) env('INCOMING_MEDIA_RETENTION_DAYS', 30),
    ],

    'openai' => [
        'api_key' => env('OPENAI_API_KEY', ''),
    ],

    'vision' => [
        'provider' => env('VISION_PROVIDER', 'openai'),
        'openai_api_key' => env('OPENAI_API_KEY', ''),
        'openai_model' => env('VISION_OPENAI_MODEL', 'gpt-4o'),
        'gemini_api_key' => env('GEMINI_API_KEY', ''),
        'gemini_model' => env('VISION_GEMINI_MODEL', 'gemini-3.8-flash'),
    ],

    'myfatoorah' => [
        'api_key'        => env('MYFATOORAH_API_KEY'),
        'base_url'       => env('MYFATOORAH_BASE_URL', 'https://api.myfatoorah.com'),
        'webhook_secret' => env('MYFATOORAH_WEBHOOK_SECRET'),
        'recurring_enabled' => env('MYFATOORAH_RECURRING_ENABLED', false),
    ],

    'paymob' => [
        'secret_key'     => env('PAYMOB_SECRET_KEY'),
        'public_key'     => env('PAYMOB_PUBLIC_KEY'),
        'hmac_key'       => env('PAYMOB_HMAC_KEY'),
        'currency'       => env('PAYMOB_CURRENCY', 'EGP'),
        'api_base'       => 'https://accept.paymob.com',
        'integration_id' => env('PAYMOB_CARD_INTEGRATION_ID'),
    ],

    'ai' => [
        'provider' => env('AI_PROVIDER', 'gemini'),
        'fallback_provider' => env('AI_FALLBACK_PROVIDER', 'claude'),
        'prompt_version' => env('AI_PROMPT_VERSION', 'v1'),
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

    'groq' => [
        'api_key' => env('GROQ_API_KEY'),
        'model' => env('GROQ_MODEL', 'openai/gpt-oss-120b'),
    ],

    'claude' => [
        'api_key' => env('ANTHROPIC_API_KEY'),
        'model' => env('CLAUDE_MODEL', 'claude-haiku-4-5-20251001'),
    ],
    'frontend_url' => env('FRONTEND_URL'),

    'operations' => [
        'alert_email' => env('OPERATIONS_ALERT_EMAIL'),
        'queue_backlog_threshold' => (int) env('QUEUE_BACKLOG_ALERT_THRESHOLD', 1000),
        'failed_job_alert_threshold' => (int) env('FAILED_JOB_ALERT_THRESHOLD', 1),
        'send_failure_rate_percent' => (int) env('SEND_FAILURE_RATE_ALERT_PERCENT', 20),
        'send_failure_min_sample' => (int) env('SEND_FAILURE_RATE_MIN_SAMPLE', 10),
        'webhook_signature_failure_threshold' => (int) env('WEBHOOK_SIGNATURE_FAILURE_ALERT_THRESHOLD', 5),
        'alert_dedup_minutes' => (int) env('OPERATIONS_ALERT_DEDUP_MINUTES', 60),
    ],

    'metrics' => [
        'avg_manual_reply_time' => (int) env('AVG_MANUAL_REPLY_TIME', 3), // minutes
        'hourly_rate' => (int) env('HOURLY_RATE', 100), // SAR per hour
    ],

];
