<?php

namespace App\Jobs;

use App\Models\Sequence;
use App\Models\SequenceStep;
use App\Models\SequenceUser;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Channel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class ProcessSequenceStep implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;
    public $backoff = 60;
    public $timeout = 120;

    public function __construct(
        public int $sequenceUserId,
        public int $priority = 5 // Medium priority for sequences
    ) {
        $this->onQueue('sequences'); // Separate queue for sequences
    }

    public function handle(): void
    {
        $sequenceUser = SequenceUser::with(['sequence', 'conversation.channel'])
            ->find($this->sequenceUserId);

        if (!$sequenceUser || $sequenceUser->status !== 'active') {
            Log::info('ProcessSequenceStep: Sequence user not active', ['sequence_user_id' => $this->sequenceUserId]);
            return;
        }

        $sequence = $sequenceUser->sequence;
        $conversation = $sequenceUser->conversation;
        $channel = $conversation->channel;

        if (!$sequence->is_active) {
            $sequenceUser->update(['status' => 'paused']);
            return;
        }

        // Get the next step
        $currentStep = $sequenceUser->current_step;
        $nextStep = SequenceStep::where('sequence_id', $sequence->id)
            ->where('step_order', $currentStep + 1)
            ->where('is_active', true)
            ->first();

        if (!$nextStep) {
            // No more steps, mark as completed
            $sequenceUser->update([
                'status' => 'completed',
                'completed_at' => now(),
            ]);
            Log::info('ProcessSequenceStep: Sequence completed', ['sequence_user_id' => $this->sequenceUserId]);
            return;
        }

        // Check if delay has passed
        $previousStepTime = $sequenceUser->updated_at;
        $delayHours = $nextStep->delay_hours;
        
        if ($previousStepTime->addHours($delayHours)->isFuture()) {
            // Delay not yet passed, reschedule
            $this->release($previousStepTime->addHours($delayHours)->diffInSeconds(now()));
            return;
        }

        // Send the message
        $message = Message::create([
            'conversation_id' => $conversation->id,
            'content' => $nextStep->message,
            'direction' => 'outbound',
            'status' => 'sequence',
            'is_ai' => false,
            'source' => 'sequence',
            'send_status' => 'pending',
        ]);

        $success = $this->sendMessageToChannel($channel, $conversation, $message);

        if ($success) {
            $sequenceUser->update(['current_step' => $nextStep->step_order]);
            
            Log::info('ProcessSequenceStep: Step completed', [
                'sequence_user_id' => $this->sequenceUserId,
                'step' => $nextStep->step_order,
            ]);

            // Schedule next step if there is one
            $nextNextStep = SequenceStep::where('sequence_id', $sequence->id)
                ->where('step_order', $nextStep->step_order + 1)
                ->where('is_active', true)
                ->first();

            if ($nextNextStep) {
                ProcessSequenceStep::dispatch($this->sequenceUserId)
                    ->delay(now()->addHours($nextNextStep->delay_hours));
            }
        } else {
            Log::error('ProcessSequenceStep: Failed to send message', [
                'sequence_user_id' => $this->sequenceUserId,
                'step' => $nextStep->step_order,
            ]);
        }
    }

    private function sendMessageToChannel(Channel $channel, Conversation $conversation, Message $message): bool
    {
        try {
            $channelType = $channel->type;
            $senderId = $conversation->sender_id;

            switch ($channelType) {
                case 'facebook':
                case 'instagram':
                    return $this->sendMetaMessage($channel, $senderId, $message->content);
                
                case 'whatsapp':
                    return $this->sendWhatsAppMessage($channel, $senderId, $message->content);
                
                default:
                    Log::warning('ProcessSequenceStep: Unsupported channel type', ['channel_type' => $channelType]);
                    return false;
            }
        } catch (\Exception $e) {
            Log::error('ProcessSequenceStep: Exception', [
                'error' => $e->getMessage(),
                'channel_id' => $channel->id,
            ]);
            return false;
        }
    }

    private function sendMetaMessage(Channel $channel, string $recipientId, string $content): bool
    {
        $pageAccessToken = $channel->page_access_token;
        $pageId = $channel->page_id;

        $response = Http::post("https://graph.facebook.com/v18.0/{$pageId}/messages", [
            'recipient' => ['id' => $recipientId],
            'message' => ['text' => $content],
        ], [
            'headers' => [
                'Authorization' => "Bearer {$pageAccessToken}",
            ],
        ]);

        return $response->successful();
    }

    private function sendWhatsAppMessage(Channel $channel, string $recipientId, string $content): bool
    {
        // WhatsApp implementation
        return false;
    }
}
