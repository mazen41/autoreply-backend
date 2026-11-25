<?php

namespace Tests\Unit;

use App\Services\AIActionClaimGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AIActionClaimGuardTest extends TestCase
{
    #[DataProvider('unsupportedMutationClaims')]
    public function test_unverified_customer_record_mutation_claims_are_replaced(string $reply, string $language = 'english'): void
    {
        $result = app(AIActionClaimGuard::class)->inspect($reply, $language);

        $this->assertTrue($result['blocked']);
        $this->assertSame('unverified_customer_record_mutation', $result['reason']);
        $this->assertNotSame($reply, $result['replacement']);
    }

    public static function unsupportedMutationClaims(): array
    {
        return [
            'address updated' => ['I updated your delivery address in the store.'],
            'saved profile' => ['We have saved your account details.'],
            'passive address update' => ['Your delivery address has been updated.'],
            'Arabic address update' => ['تم تحديث عنوان التوصيل الخاص بك.', 'arabic'],
        ];
    }

    public function test_checkout_details_saved_in_conversation_are_not_mistaken_for_store_mutation(): void
    {
        $result = app(AIActionClaimGuard::class)->inspect('I saved your order details in this conversation.');

        $this->assertFalse($result['blocked']);
    }

    public function test_verified_successful_mutation_allows_confirmation(): void
    {
        $result = app(AIActionClaimGuard::class)->inspect('I updated your delivery address in the store.', verifiedMutationSucceeded: true);

        $this->assertFalse($result['blocked']);
    }
}
