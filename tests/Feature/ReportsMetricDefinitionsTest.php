<?php

namespace Tests\Feature;

use App\Models\BusinessProfile;
use App\Models\Channel;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ReportsMetricDefinitionsTest extends TestCase
{
    use RefreshDatabase;

    private function reportFixture(): array
    {
        $user = User::factory()->create();
        $business = BusinessProfile::factory()->create(['user_id' => $user->id, 'timezone' => 'UTC']);
        $channel = Channel::factory()->create(['user_id' => $user->id, 'business_id' => $business->id]);
        $conversation = Conversation::factory()->create([
            'business_id' => $business->id,
            'channel_id' => $channel->id,
            'created_at' => now()->subDays(2),
        ]);
        Sanctum::actingAs($user);

        return compact('user', 'business', 'channel', 'conversation');
    }

    public function test_ai_share_and_manual_interventions_use_customer_replies_only(): void
    {
        $fixture = $this->reportFixture();
        $this->createMessage($fixture['conversation'], 'inbound', false, 'instagram', 'Hello');
        $this->createMessage($fixture['conversation'], 'outbound', true, 'ai', 'AI reply');
        $this->createMessage($fixture['conversation'], 'outbound', false, 'instagram', 'Human reply');
        $this->createMessage($fixture['conversation'], 'outbound', false, 'campaign', 'Marketing send');

        $data = $this->withHeader('X-Business-Id', (string) $fixture['business']->id)
            ->getJson('/api/reports/ai-performance?days=30')
            ->assertOk()
            ->json();

        $this->assertSame(2, $data['total_replies']);
        $this->assertSame(1, $data['manual_interventions']);
        $this->assertSame(1, $data['ai_conversations']);
        $this->assertEquals(50.0, $data['ai_share_of_replies']);
    }

    public function test_top_questions_are_real_repeated_questions_only(): void
    {
        $fixture = $this->reportFixture();
        $this->createMessage($fixture['conversation'], 'inbound', false, 'instagram', 'Where is my order?');
        $this->createMessage($fixture['conversation'], 'inbound', false, 'instagram', 'Where is my order');
        $this->createMessage($fixture['conversation'], 'inbound', false, 'instagram', 'Hi');
        $this->createMessage($fixture['conversation'], 'inbound', false, 'instagram', 'You told me what yesterday');

        $data = $this->withHeader('X-Business-Id', (string) $fixture['business']->id)
            ->getJson('/api/reports/top-questions?days=30')
            ->assertOk()
            ->json();

        $this->assertCount(1, $data['questions']);
        $this->assertSame('where is my order', $data['questions'][0]['question']);
        $this->assertSame(2, $data['questions'][0]['count']);
    }

    public function test_report_day_limit_is_capped_at_365(): void
    {
        $fixture = $this->reportFixture();

        $this->withHeader('X-Business-Id', (string) $fixture['business']->id)
            ->getJson('/api/reports/ai-performance?days=366')
            ->assertUnprocessable();
    }

    public function test_time_saved_uses_business_estimates_and_selected_period(): void
    {
        $fixture = $this->reportFixture();
        $this->createMessage($fixture['conversation'], 'outbound', true, 'ai', 'AI reply');
        $this->withHeader('X-Business-Id', (string) $fixture['business']->id)
            ->putJson('/api/reports/time-saved/settings', [
                'minutes_per_reply' => 5,
                'hourly_rate' => 200,
                'currency' => 'EGP',
            ])
            ->assertOk();

        $estimate = $this->withHeader('X-Business-Id', (string) $fixture['business']->id)
            ->getJson('/api/reports/time-saved?days=30')
            ->assertOk()
            ->json();

        $this->assertSame(1, $estimate['messages_handled']);
        $this->assertSame(5, $estimate['estimate_inputs']['minutes_per_reply']);
        $this->assertEquals(200.0, $estimate['estimate_inputs']['hourly_rate']);
        $this->assertSame('EGP', $estimate['estimate_inputs']['hourly_rate_currency']);
        $this->assertSame(17, $estimate['estimated_value']);
        $this->assertTrue($estimate['estimate_inputs']['is_estimate']);
    }

    public function test_csv_export_neutralizes_formula_leading_customer_content(): void
    {
        $fixture = $this->reportFixture();
        $this->createMessage($fixture['conversation'], 'inbound', false, 'instagram', '=HYPERLINK("https://bad.example")');

        $response = $this->withHeader('X-Business-Id', (string) $fixture['business']->id)
            ->get('/api/reports/export/csv?type=messages');

        $response->assertOk();
        ob_start();
        $response->baseResponse->sendContent();
        $csv = ob_get_clean();

        $this->assertStringContainsString("'=HYPERLINK", $csv);
    }

    public function test_pdf_export_uses_unicode_text_and_discloses_row_limit_and_period(): void
    {
        $fixture = $this->reportFixture();
        for ($index = 0; $index < 100; $index++) {
            $this->createMessage($fixture['conversation'], 'inbound', false, 'instagram', "Question {$index}?");
        }
        $this->createMessage($fixture['conversation'], 'inbound', false, 'instagram', 'طلب جديد في القاهرة');

        $response = $this->withHeader('X-Business-Id', (string) $fixture['business']->id)
            ->get('/api/reports/export/pdf?type=messages&days=30');
        $response->assertOk();

        ob_start();
        $response->baseResponse->sendContent();
        $pdfBytes = ob_get_clean();
        $this->assertStringStartsWith('%PDF-', $pdfBytes);

        $text = (new \Smalot\PdfParser\Parser())->parseContent($pdfBytes)->getText();
        $text = str_replace(['ﬁ', 'ﬂ'], ['fi', 'fl'], $text);
        $this->assertStringContainsString('Showing the first 100 of 101 rows.', $text);
        $normalized = \Normalizer::normalize($text, \Normalizer::FORM_KC);
        $arabicWords = array_values(array_filter(preg_split('/\s+/u', $normalized) ?: [], fn (string $word) => preg_match('/[\x{0600}-\x{06FF}]/u', $word)));
        $logicalArabic = implode(' ', array_map(
            fn (string $word) => implode('', array_reverse(preg_split('//u', $word, -1, PREG_SPLIT_NO_EMPTY) ?: [])),
            array_reverse($arabicWords)
        ));
        $this->assertStringContainsString('طلب جديد في القاهرة', $logicalArabic);
        $this->assertStringContainsString('Report', $text);
    }

    private function createMessage(Conversation $conversation, string $direction, bool $isAi, ?string $source, string $content): void
    {
        Message::factory()->create([
            'conversation_id' => $conversation->id,
            'direction' => $direction,
            'is_ai' => $isAi,
            'source' => $source,
            'content' => $content,
            'created_at' => now()->subDay(),
            'updated_at' => now()->subDay(),
        ]);
    }
}
