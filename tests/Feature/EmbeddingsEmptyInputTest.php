<?php

namespace Tests\Feature;

use App\Services\EmbeddingsService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class EmbeddingsEmptyInputTest extends TestCase
{
    public function test_empty_text_is_not_sent_to_the_embedding_api(): void
    {
        Http::fake();

        $this->assertNull(app(EmbeddingsService::class)->embedChunk(''));
        $this->assertNull(app(EmbeddingsService::class)->embedChunk("   \n "));

        Http::assertNothingSent();
    }
}