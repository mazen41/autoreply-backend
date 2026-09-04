<?php

namespace App\Console\Commands;

use App\Models\Conversation;
use App\Models\Sequence;
use App\Services\SequenceTriggerService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CheckNoReplySequences extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'sequences:check-no-reply';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Check for conversations with no reply and enroll in no-reply sequences';

    /**
     * Execute the console command.
     */
    public function handle(SequenceTriggerService $sequenceTriggerService): int
    {
        $this->info('Starting no-reply sequence check...');

        // Get all active sequences with no_reply trigger
        $noReplySequences = Sequence::where('status', 'active')
            ->where('trigger_type', 'no_reply')
            ->get();

        if ($noReplySequences->isEmpty()) {
            $this->info('No active no-reply sequences found.');
            return Command::SUCCESS;
        }

        $this->info("Found {$noReplySequences->count()} active no-reply sequences.");

        $enrolledCount = 0;

        foreach ($noReplySequences as $sequence) {
            $this->info("Processing sequence: {$sequence->name}");

            try {
                $triggerConfig = $sequence->trigger_config ?? [];
                $hoursWithoutReply = $triggerConfig['hours'] ?? 24;

                // Get conversations where the last message was from business (outbound)
                // and sent more than the configured hours ago (customer hasn't replied)
                $threshold = now()->subHours($hoursWithoutReply);

                $conversations = Conversation::where('business_id', $sequence->business_id)
                    ->whereExists(function ($query) use ($threshold) {
                        $query->select(DB::raw(1))
                            ->from('messages')
                            ->whereColumn('messages.conversation_id', 'conversations.id')
                            ->where('direction', 'outbound')
                            ->where('created_at', '<=', $threshold)
                            ->where('id', function ($subQuery) {
                                $subQuery->select(DB::raw('MAX(id)'))
                                    ->from('messages')
                                    ->whereColumn('messages.conversation_id', 'conversations.id');
                            });
                    })
                    ->whereDoesntHave('sequenceEnrollments', function ($query) use ($sequence) {
                        $query->where('sequence_id', $sequence->id)
                            ->where('status', 'active');
                    })
                    ->get();

                $this->info("Found {$conversations->count()} eligible conversations for sequence {$sequence->id}");

                foreach ($conversations as $conversation) {
                    try {
                        $sequenceTriggerService->checkAndEnrollForNoReply($conversation);
                        $enrolledCount++;
                        $this->info("Enrolled conversation {$conversation->id} in sequence {$sequence->id}");
                    } catch (\Exception $e) {
                        Log::error('Failed to enroll conversation in no-reply sequence', [
                            'sequence_id' => $sequence->id,
                            'conversation_id' => $conversation->id,
                            'error' => $e->getMessage(),
                        ]);
                        $this->error("Failed to enroll conversation {$conversation->id}: {$e->getMessage()}");
                    }
                }
            } catch (\Exception $e) {
                Log::error('Failed to process no-reply sequence', [
                    'sequence_id' => $sequence->id,
                    'error' => $e->getMessage(),
                ]);
                $this->error("Failed to process sequence {$sequence->id}: {$e->getMessage()}");
            }
        }

        $this->info("No-reply sequence check completed. Enrolled {$enrolledCount} conversations.");
        return Command::SUCCESS;
    }
}
