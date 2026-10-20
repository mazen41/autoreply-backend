<?php

namespace Tests\Unit;

use App\Support\SensitiveLogContext;
use PHPUnit\Framework\TestCase;

class SensitiveLogContextTest extends TestCase
{
    public function test_recursively_redacts_oauth_credentials_and_customer_data(): void
    {
        $result = SensitiveLogContext::redact([
            'code' => 'oauth-code',
            'state' => 'oauth-state',
            'access_token' => 'provider-token',
            'profile' => [
                'emailAddress' => 'customer@example.test',
                'phone_number' => '+201155512345',
                'name' => 'Customer Name',
            ],
            'message' => ['text' => 'Private message body'],
            'status' => 'connected',
        ]);

        self::assertSame('[redacted]', $result['code']);
        self::assertSame('[redacted]', $result['state']);
        self::assertSame('[redacted]', $result['access_token']);
        self::assertSame('[redacted]', $result['profile']['emailAddress']);
        self::assertSame('[redacted]', $result['profile']['phone_number']);
        self::assertSame('[redacted]', $result['profile']['name']);
        self::assertSame('[redacted]', $result['message']);
        self::assertSame('connected', $result['status']);
    }

    public function test_redacts_credentials_from_unstructured_log_text(): void
    {
        $log = 'request {"access_token":"provider-secret","client_secret": private-secret} Authorization: Bearer eyJhbGciOiJIUzI1NiJ9';
        $redacted = SensitiveLogContext::redactText($log);

        self::assertStringNotContainsString('provider-secret', $redacted);
        self::assertStringNotContainsString('private-secret', $redacted);
        self::assertStringNotContainsString('eyJhbGciOiJIUzI1NiJ9', $redacted);
        self::assertSame(3, substr_count($redacted, '[redacted]'));
    }
}
