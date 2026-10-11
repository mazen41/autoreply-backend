<?php

namespace Tests\Feature;

use App\Mail\ScheduledTaskFailureMail;
use App\Models\BusinessProfile;
use App\Models\Channel;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Support\OperationalMetricCounter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

class OperationalMonitoringTest extends TestCase
{
    use RefreshDatabase;

    public function test_scheduled_events_use_bounded_overlap_locks(): void
    {
        // The console kernel loads the schedule definition during command boot.
        $this->artisan('schedule:list')->assertExitCode(0);
        $events = app(Schedule::class)->events();

        $this->assertNotEmpty($events);
        foreach ($events as $event) {
            $this->assertTrue($event->withoutOverlapping, 'Scheduled event must use an overlap lock.');
            $this->assertGreaterThan(0, $event->expiresAt, 'Scheduled event lock must expire.');
            $this->assertLessThanOrEqual(300, $event->expiresAt, 'Scheduled event lock must be bounded.');
        }

        $gmailPoll = collect($events)->first(fn ($event) => $event->description === 'poll-gmail');
        $this->assertNotNull($gmailPoll);
        $this->assertSame(5, $gmailPoll->expiresAt);

        $failureCallbacks = new \ReflectionProperty($gmailPoll, 'afterCallbacks');
        $failureCallbacks->setAccessible(true);
        $this->assertNotEmpty($failureCallbacks->getValue($gmailPoll), 'Gmail dispatch failures must be reported.');
    }

    public function test_monitor_alerts_on_signature_failures_and_per_channel_send_failure_rate(): void
    {
        Cache::flush();
        config([
            'services.operations.alert_email' => null,
            'services.operations.queue_backlog_threshold' => 1000,
            'services.operations.send_failure_rate_percent' => 20,
            'services.operations.send_failure_min_sample' => 10,
            'services.operations.webhook_signature_failure_threshold' => 5,
        ]);
        foreach (range(1, 5) as $_) {
            app(OperationalMetricCounter::class)->increment('webhook_signature_failures');
        }

        $user = User::factory()->create();
        $business = BusinessProfile::factory()->create(['user_id' => $user->id]);
        $channel = Channel::factory()->create([
            'user_id' => $user->id,
            'business_id' => $business->id,
            'type' => 'facebook',
            'status' => 'connected',
        ]);
        $conversation = Conversation::factory()->create([
            'business_id' => $business->id,
            'channel_id' => $channel->id,
        ]);
        foreach (range(1, 10) as $index) {
            Message::create([
                'conversation_id' => $conversation->id,
                'content' => 'redacted test message',
                'direction' => 'outbound',
                'status' => $index <= 2 ? 'failed' : 'sent',
                'send_status' => $index <= 2 ? 'failed' : 'sent',
                'delivery_status' => $index <= 2 ? 'failed' : 'sent',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        Log::spy();
        $this->artisan('monitor:operations')->assertExitCode(0);

        Log::shouldHaveReceived('alert')->withArgs(function (string $message, array $context): bool {
            return $message === 'Operational monitoring threshold reached'
                && in_array($context['metric'] ?? null, ['webhook_signature_failures', 'send_failure_channel_' . ($context['channel_id'] ?? '')], true);
        })->atLeast()->twice();
    }

    public function test_scheduled_failure_notifier_emails_only_the_configured_operations_recipient(): void
    {
        Mail::fake();
        config(['services.operations.alert_email' => 'ops@example.test']);

        app(\App\Services\ScheduledFailureNotifier::class)->notify('analytics:daily');

        Mail::assertSent(ScheduledTaskFailureMail::class, function (ScheduledTaskFailureMail $mail): bool {
            return $mail->taskName === 'analytics:daily'
                && $mail->hasTo('ops@example.test');
        });
    }
}
