<?php

namespace Tests\Feature;

use App\Http\Controllers\WebhookController;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AttachmentsNotReadTest extends TestCase
{
    public function test_attachments_are_not_read_by_default(): void
    {
        $this->assertFalse(config('attachments.read_enabled'));
    }

    public function test_meta_attachment_download_makes_no_request_when_reading_is_off(): void
    {
        Http::fake();
        $controller = app(WebhookController::class);
        $method = new \ReflectionMethod($controller, 'downloadMetaAttachment');
        $method->setAccessible(true);

        $result = $method->invoke($controller, [
            'type' => 'image',
            'url' => 'https://lookaside.fbsbx.com/example.jpg',
        ]);

        $this->assertNull($result);
        Http::assertNothingSent();
    }

    public function test_coordinates_are_not_taken_from_attachments_when_reading_is_off(): void
    {
        $controller = app(WebhookController::class);
        $method = new \ReflectionMethod($controller, 'findMetaCoordinates');
        $method->setAccessible(true);

        $this->assertNull($method->invoke($controller, ['lat' => 24.7, 'long' => 46.6]));
    }
}