<?php

namespace Tests\Feature;

use App\Models\BusinessProfile;
use App\Models\TeamMember;
use App\Models\User;
use App\Services\ActiveBusiness;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class ActiveBusinessResolutionTest extends TestCase
{
    use RefreshDatabase;

    public function test_business_must_be_explicit_and_owned_or_an_active_membership(): void
    {
        $ownerA = User::factory()->create();
        $businessA = BusinessProfile::factory()->create(['user_id' => $ownerA->id]);
        $ownerA->update(['business_id' => $businessA->id]);
        $ownerB = User::factory()->create();
        $businessB = BusinessProfile::factory()->create(['user_id' => $ownerB->id]);
        $member = User::factory()->create();
        TeamMember::create([
            'business_id' => $businessA->id,
            'user_id' => $member->id,
            'role' => 'manager',
            'is_active' => true,
        ]);

        $resolver = app(ActiveBusiness::class);

        try {
            $resolver->resolve(Request::create('/api/test'), $ownerA);
            $this->fail('A primary business must not be silently selected.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $exception) {
            $this->assertSame(422, $exception->getStatusCode());
        }

        $selectedOwnerRequest = Request::create('/api/test', 'GET', server: ['HTTP_X_BUSINESS_ID' => (string) $businessA->id]);
        $this->assertSame($businessA->id, $resolver->resolve($selectedOwnerRequest, $ownerA)->id);

        $selectedMemberRequest = Request::create('/api/test', 'GET', server: ['HTTP_X_BUSINESS_ID' => (string) $businessA->id]);
        $this->assertSame($businessA->id, $resolver->resolve($selectedMemberRequest, $member)->id);

        $foreignRequest = Request::create('/api/test', 'GET', server: ['HTTP_X_BUSINESS_ID' => (string) $businessB->id]);
        try {
            $resolver->resolve($foreignRequest, $ownerA);
            $this->fail('A foreign business must be rejected.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
    }
}
