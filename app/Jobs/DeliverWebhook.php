<?php

namespace App\Jobs;

use App\Models\Webhook;
use App\Services\WebhookService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class DeliverWebhook implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(
        public readonly int $webhookId,
        public readonly string $event,
        public readonly array $payload,
        public readonly bool $testOnly = false,
    ) {}

    public function handle(WebhookService $service): void
    {
        $query = Webhook::query();
        if (!$this->testOnly) {
            $query->active()->forEvent($this->event);
        }
        $webhook = $query->find($this->webhookId);

        if ($webhook) {
            $service->deliver($webhook, $this->event, $this->payload);
        }
    }
}
