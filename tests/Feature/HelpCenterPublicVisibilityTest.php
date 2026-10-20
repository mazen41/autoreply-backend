<?php

namespace Tests\Feature;

use App\Models\BusinessProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HelpCenterPublicVisibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_published_articles_are_searchable_or_publicly_readable(): void
    {
        $owner = User::factory()->create();
        $business = BusinessProfile::factory()->create(['user_id' => $owner->id]);
        $headers = ['X-Business-Id' => (string) $business->id];

        $draft = $this->actingAs($owner, 'sanctum')->withHeaders($headers)->postJson('/api/help-center/articles', [
            'title' => 'Private returns policy', 'category' => 'Orders', 'content' => 'Internal draft text.', 'status' => 'draft',
        ])->assertCreated()->json('article');
        $this->getJson("/api/public/help-center/{$business->id}?q=returns")
            ->assertOk()->assertJsonCount(0, 'articles');
        $this->getJson("/api/public/help-center/{$business->id}/{$draft['slug']}")->assertNotFound();

        $published = $this->patchJson("/api/help-center/articles/{$draft['id']}", ['status' => 'published'])
            ->assertOk()->json('article');
        $this->getJson("/api/public/help-center/{$business->id}?q=returns")
            ->assertOk()->assertJsonPath('articles.0.title', 'Private returns policy');
        $this->getJson("/api/public/help-center/{$business->id}/{$published['slug']}")
            ->assertOk()->assertJsonPath('article.content', 'Internal draft text.');
    }

    public function test_help_article_management_and_public_read_are_business_scoped(): void
    {
        $owner = User::factory()->create();
        $business = BusinessProfile::factory()->create(['user_id' => $owner->id]);
        $foreignBusiness = BusinessProfile::factory()->create();
        $foreignArticle = \App\Models\HelpCenterArticle::create([
            'business_id' => $foreignBusiness->id, 'title' => 'Foreign', 'slug' => 'foreign',
            'category' => 'General', 'content' => 'Foreign content', 'status' => 'published',
        ]);

        $this->actingAs($owner, 'sanctum')->withHeader('X-Business-Id', (string) $business->id)
            ->patchJson("/api/help-center/articles/{$foreignArticle->id}", ['title' => 'Changed'])->assertNotFound();
        $this->getJson("/api/public/help-center/{$business->id}/foreign")->assertNotFound();
    }
}
