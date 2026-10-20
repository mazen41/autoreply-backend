<?php

namespace App\Support;

class SensitiveLogContext
{
    private const SENSITIVE_KEYS = [
        'token', 'access_token', 'refresh_token', 'client_secret', 'secret',
        'password', 'authorization', 'code', 'state', 'email', 'email_address',
        'phone', 'phone_number', 'mobile', 'address', 'street', 'postal_code',
        'message', 'message_text', 'content', 'body', 'payload', 'subject',
        'name', 'full_name', 'first_name', 'last_name', 'sender', 'sender_id',
        'recipient', 'recipient_id', 'coordinates', 'lat', 'lng', 'latitude',
        'longitude', 'hmac', 'signature', 'ip', 'ip_address', 'url',
        'redirect_uri', 'page_name', 'page_id', 'instagram_account_id', 'business_name',
    ];

    public static function redact(mixed $value): mixed
    {
        if (is_array($value)) {
            $redacted = [];
            foreach ($value as $key => $item) {
                $normalizedKey = is_string($key)
                    ? strtolower(str_replace('-', '_', preg_replace('/(?<!^)[A-Z]/', '_$0', $key)))
                    : null;
                if ($normalizedKey !== null && in_array($normalizedKey, self::SENSITIVE_KEYS, true)) {
                    $redacted[$key] = '[redacted]';
                } else {
                    $redacted[$key] = self::redact($item);
                }
            }
            return $redacted;
        }

        if (is_object($value)) {
            return self::redact((array) $value);
        }

        return $value;
    }

    public static function redactText(string $text): string
    {
        $text = preg_replace(
            '/(["\']?(?:access[_-]?token|refresh[_-]?token|client[_-]?secret|api[_-]?key|password|authorization|webhook[_-]?(?:verify[_-]?)?token|signature|hmac)["\']?\s*[:=]\s*)("(?:\\\\.|[^"\\\\])*"|\'(?:\\\\.|[^\'\\\\])*\'|Bearer\s+[A-Za-z0-9._~+\/-]+=*|[^,\s}\]]+)/i',
            '$1[redacted]',
            $text,
        ) ?? $text;

        return preg_replace('/\bBearer\s+[A-Za-z0-9._~+\/-]+=*/i', 'Bearer [redacted]', $text) ?? $text;
    }
}
