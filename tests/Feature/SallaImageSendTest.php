<?php

namespace Tests\Feature;

use App\Jobs\ProcessAutoReply;
use App\Models\BusinessProfile;
use App\Models\Channel;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Package;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Regression tests for the production incident where the bot told customers
 * "here are the photos" / "here are the product images" while sending ZERO
 * actual media (confirmed via Evolution webhook logs: messageType was plain
 * "conversation" text, no image/media payload).
 *
 * Root cause: SallaService::formatProductsListForAI() read `main_image` /
 * `images[0].url`, but Salla's LIST products endpoint actually returns the
 * image under `thumbnail`. So image_url was always null, $images ended up
 * empty, and the image-send loop silently ran zero times -- while the AI's
 * text still confidently claimed images were sent.
 *
 * These tests assert BOTH sides: that a real media-send API call happened,
 * AND that the reply text's claim matches what actually happened. A
 * text-only assertion ("reply mentions photos") would have let the original
 * bug pass silently -- that's exactly the gap being closed here.
 */
class SallaImageSendTest extends TestCase
{
    use RefreshDatabase;

    private function makeFreePackage(): Package
    {
        return Package::create([
            'name' => 'Free',
            'name_ar' => 'مجاني',
            'price_monthly' => 0,
            'price_yearly' => 0,
            'ai_replies_limit' => -1,
            'is_active' => true,
        ]);
    }

    private function makeFixture(string $inboundContent, string $priorAiMessage): array
    {
        $this->makeFreePackage();

        $user = User::forceCreate([
            'name' => 'Test',
            'email' => 'test' . rand() . '@example.com',
            'password' => bcrypt('password'),
        ]);

        $business = BusinessProfile::create([
            'user_id' => $user->id,
            'business_name' => 'Demo Store',
        ]);

        $whatsappChannel = Channel::create([
            'user_id' => $user->id,
            'business_id' => $business->id,
            'type' => 'whatsapp',
            'page_id' => 'instance-image-test',
            'status' => 'connected',
            'access_token' => 'test_token_' . \Illuminate\Support\Str::random(10),
            'ai_enabled' => true,
        ]);

        $sallaChannel = Channel::create([
            'user_id' => $user->id,
            'business_id' => $business->id,
            'type' => 'salla',
            'status' => 'connected',
            'access_token' => 'fake-salla-access-token',
            'refresh_token' => 'fake-salla-refresh-token',
        ]);

        $conversation = Conversation::create([
            'channel_id' => $whatsappChannel->id,
            'business_id' => $business->id,
            'sender_id' => '966500000000',
            'status' => 'open',
        ]);

        Message::create([
            'conversation_id' => $conversation->id,
            'content' => $priorAiMessage,
            'direction' => 'outbound',
            'status' => 'sent',
            'is_ai' => true,
            'intent' => 'question',
            'send_status' => 'sent',
        ]);

        $message = Message::create([
            'conversation_id' => $conversation->id,
            'content' => $inboundContent,
            'direction' => 'inbound',
            'status' => 'received',
            'is_ai' => false,
            'send_status' => 'received',
        ]);

        return compact('user', 'business', 'whatsappChannel', 'sallaChannel', 'conversation', 'message');
    }

    private function fakeGeminiReply(string $replyText): array
    {
        return [
            'candidates' => [[
                'content' => [
                    'parts' => [[
                        'text' => json_encode([
                            'reply' => $replyText,
                            'intent' => 'question',
                            'needs_escalation' => false,
                            'needs_images' => true,
                            'confidence' => 0.9,
                            'escalation_reason' => 'none',
                        ]),
                    ]],
                ],
            ]],
        ];
    }

    /**
     * Happy path: Salla's real response shape (thumbnail field, as documented)
     * must result in an ACTUAL Evolution media-send call, and the AI's
     * "here are the photos" claim is allowed through because it's true.
     */
    public function test_real_thumbnail_field_results_in_actual_image_send(): void
    {
        $fixture = $this->makeFixture(
            'can I see photos of the dress and pants',
            'Here are some of our products: 1. Dress (174 SAR), 2. Pants (94 SAR).'
        );

        Http::fake([
            'generativelanguage.googleapis.com/*embedContent*' => Http::response(['embedding' => ['values' => []]], 200),
            'generativelanguage.googleapis.com/*generateContent*' => Http::response(
                $this->fakeGeminiReply('Here are the photos of the dress and pants!'),
                200
            ),
            'api.salla.dev/admin/v2/products*' => Http::response([
                'data' => [
                    ['id' => 1, 'name' => 'Dress', 'price' => ['amount' => 174, 'currency_code' => 'SAR'], 'quantity' => 5, 'thumbnail' => 'https://cdn.salla.sa/dress.jpg'],
                    ['id' => 2, 'name' => 'Pants', 'price' => ['amount' => 94, 'currency_code' => 'SAR'], 'quantity' => 3, 'thumbnail' => 'https://cdn.salla.sa/pants.jpg'],
                ],
                'pagination' => ['total' => 2, 'per_page' => 10, 'current_page' => 1],
            ], 200),
            'localhost:8080/message/sendMedia/*' => Http::response(['key' => ['id' => 'MEDIA_MSG_1']], 200),
            'localhost:8080/message/sendText/*' => Http::response(['key' => ['id' => 'TEXT_MSG_1']], 200),
        ]);

        (new ProcessAutoReply($fixture['message']->id))->handle();

        // A real media-send call must have happened, with a real image URL.
        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/message/sendMedia/')
                && ($request->data()['media'] ?? null) !== null
                && str_contains($request->data()['media'], 'salla.sa');
        });

        $reply = Message::where('conversation_id', $fixture['conversation']->id)
            ->where('direction', 'outbound')->where('is_ai', true)->latest('id')->first();

        // Claim is true here, so it's allowed to stand.
        $this->assertStringContainsString('photos', strtolower($reply->content));
    }

    /**
     * The exact incident: no usable image URL is available (simulating the
     * `main_image`/`thumbnail` mismatch, or any other future failure to
     * resolve a real image), the AI still writes "here are the photos", and
     * the fix must override that text AND must NOT have made a fabricated
     * claim without a corresponding real send.
     */
    public function test_false_image_claim_is_overridden_when_no_image_available(): void
    {
        $fixture = $this->makeFixture(
            'can I see photos of the dress and pants',
            'Here are some of our products: 1. Dress (174 SAR), 2. Pants (94 SAR).'
        );

        Http::fake([
            'generativelanguage.googleapis.com/*embedContent*' => Http::response(['embedding' => ['values' => []]], 200),
            'generativelanguage.googleapis.com/*generateContent*' => Http::response(
                $this->fakeGeminiReply('Here are the photos of the dress and pants!'),
                200
            ),
            // No thumbnail/main_image/images field at all on either product.
            'api.salla.dev/admin/v2/products*' => Http::response([
                'data' => [
                    ['id' => 1, 'name' => 'Dress', 'price' => ['amount' => 174, 'currency_code' => 'SAR'], 'quantity' => 5],
                    ['id' => 2, 'name' => 'Pants', 'price' => ['amount' => 94, 'currency_code' => 'SAR'], 'quantity' => 3],
                ],
                'pagination' => ['total' => 2, 'per_page' => 10, 'current_page' => 1],
            ], 200),
            'localhost:8080/message/sendText/*' => Http::response(['key' => ['id' => 'TEXT_MSG_1']], 200),
        ]);

        (new ProcessAutoReply($fixture['message']->id))->handle();

        // No media-send call should have been attempted -- there was nothing to send.
        Http::assertNotSent(function ($request) {
            return str_contains($request->url(), '/message/sendMedia/');
        });

        $reply = Message::where('conversation_id', $fixture['conversation']->id)
            ->where('direction', 'outbound')->where('is_ai', true)->latest('id')->first();

        $this->assertNotNull($reply);
        // The AI's false claim must NOT reach the customer.
        $this->assertStringNotContainsString('here are the photos', strtolower($reply->content));
        // An honest fallback must be sent instead.
        $this->assertTrue(
            str_contains(strtolower($reply->content), 'not able to send')
            || str_contains($reply->content, 'لا أستطيع إرسال')
        );
    }

    /**
     * If the media-send call itself throws (e.g. Evolution returns 400/500),
     * the same honesty guard must kick in even though a valid image URL
     * existed and was attempted.
     */
    public function test_false_image_claim_is_overridden_when_send_api_call_fails(): void
    {
        $fixture = $this->makeFixture(
            'can I see photos of the dress and pants',
            'Here are some of our products: 1. Dress (174 SAR), 2. Pants (94 SAR).'
        );

        Http::fake([
            'generativelanguage.googleapis.com/*embedContent*' => Http::response(['embedding' => ['values' => []]], 200),
            'generativelanguage.googleapis.com/*generateContent*' => Http::response(
                $this->fakeGeminiReply('Here are the photos of the dress and pants!'),
                200
            ),
            'api.salla.dev/admin/v2/products*' => Http::response([
                'data' => [
                    ['id' => 1, 'name' => 'Dress', 'price' => ['amount' => 174, 'currency_code' => 'SAR'], 'quantity' => 5, 'thumbnail' => 'https://cdn.salla.sa/dress.jpg'],
                ],
                'pagination' => ['total' => 1, 'per_page' => 10, 'current_page' => 1],
            ], 200),
            // Evolution rejects every media-send attempt.
            'localhost:8080/message/sendMedia/*' => Http::response(['error' => 'bad request'], 400),
            'localhost:8080/message/sendText/*' => Http::response(['key' => ['id' => 'TEXT_MSG_1']], 200),
        ]);

        (new ProcessAutoReply($fixture['message']->id))->handle();

        // The send WAS attempted...
        Http::assertSent(fn($r) => str_contains($r->url(), '/message/sendMedia/'));

        // ...but since it failed, the text must not claim success.
        $reply = Message::where('conversation_id', $fixture['conversation']->id)
            ->where('direction', 'outbound')->where('is_ai', true)->latest('id')->first();

        $this->assertStringNotContainsString('here are the photos', strtolower($reply->content));
    }
}
