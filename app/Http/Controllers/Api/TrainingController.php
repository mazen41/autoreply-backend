<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MessageCorrection;
use App\Models\BusinessProfile;
use App\Models\Bot;
use App\Models\BotKnowledgeAssignment;
use App\Services\TrainingStatsService;
use App\Services\KnowledgeFileIngestionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class TrainingController extends Controller
{
    /**
     * Get message corrections for training review
     */
    public function getCorrections(Request $request)
    {
        $business = app(\App\Services\ActiveBusiness::class)->resolve($request, permission: 'read');
        
        $corrections = MessageCorrection::with(['originalMessage.conversation', 'approvedBy'])
            ->whereHas('originalMessage.conversation', function ($query) use ($business) {
                $query->where('business_id', $business->id);
            })
            ->orderBy('created_at', 'desc')
            ->get();

        $redactor = app(\App\Services\PiiRedactor::class);
        foreach ($corrections as $correction) {
            $correction->ai_draft = $redactor->redact((string) $correction->ai_draft)['text'];
            $correction->human_correction = $redactor->redact((string) $correction->human_correction)['text'];
            $correction->makeHidden('approvedBy');
            if ($correction->originalMessage) {
                $correction->originalMessage->content = $redactor->redact((string) $correction->originalMessage->content)['text'];
                $correction->originalMessage->makeHidden('conversation');
            }
        }

        return response()->json([
            'corrections' => $corrections,
            'total' => $corrections->count(),
            'pending_review' => $corrections->where('approved', false)->count(),
        ]);
    }

    /**
     * Approve a correction for training
     */
    public function approveCorrection(Request $request, $id)
    {
        $user = Auth::user();
        $business = app(\App\Services\ActiveBusiness::class)->resolve($request, permission: 'write');
        $correction = MessageCorrection::with('originalMessage.conversation.channel')
            ->whereHas('originalMessage.conversation', fn ($query) => $query->where('business_id', $business->id))
            ->find($id);

        if (!$correction) {
            return response()->json(['error' => 'Correction not found'], 404);
        }

        $validated = $request->validate($correction->learning_type === 'faq'
            ? ['question' => 'required|string|min:5|max:300']
            : ['question' => 'nullable|string|min:5|max:300']);

        $correction->update([
            'approved' => true,
            'approved_by' => $user->id,
            'approved_at' => now(),
        ]);

        // Apply the correction to training data
        $this->applyCorrectionToTraining($correction, $validated['question'] ?? null);

        $responseMessage = match ($correction->learning_type) {
            'faq' => 'Added to FAQs for this business.',
            'knowledge' => 'Saved as a knowledge file and indexing started.',
            default => 'Recorded for review. It does not change replies yet.',
        };

        Log::info('Message correction approved', [
            'correction_id' => $id,
            'user_id' => $user->id,
            'learning_type' => $correction->learning_type
        ]);

        return response()->json([
            'message' => $responseMessage,
        ]);
    }

    /**
     * Reject a correction
     */
    public function rejectCorrection(Request $request, $id)
    {
        $business = app(\App\Services\ActiveBusiness::class)->resolve($request, permission: 'write');
        $correction = MessageCorrection::with('originalMessage.conversation.channel')
            ->whereHas('originalMessage.conversation', fn ($query) => $query->where('business_id', $business->id))
            ->find($id);

        if (!$correction) {
            return response()->json(['error' => 'Correction not found'], 404);
        }

        $correction->delete();

        return response()->json(['message' => 'Correction rejected']);
    }

    /**
     * Add correction from manual human override
     */
    public function createCorrection(Request $request)
    {
        $user = Auth::user();
        $business = app(\App\Services\ActiveBusiness::class)->resolve($request, permission: 'write');
        
        $validated = $request->validate([
            'original_message_id' => 'required|exists:messages,id',
            'ai_draft' => 'required|string',
            'human_correction' => 'required|string',
            'learning_type' => 'nullable|in:faq,tone,knowledge',
        ]);

        // Verify ownership
        $originalMessage = \App\Models\Message::with('conversation.channel')
            ->whereHas('conversation', fn ($query) => $query->where('business_id', $business->id))
            ->findOrFail($validated['original_message_id']);

        $correction = MessageCorrection::create([
            'original_message_id' => $validated['original_message_id'],
            'ai_draft' => $validated['ai_draft'],
            'human_correction' => $validated['human_correction'],
            'learning_type' => $validated['learning_type'] ?? 'general',
        ]);

        Log::info('Message correction created', [
            'correction_id' => $correction->id,
            'user_id' => $user->id,
            'learning_type' => $correction->learning_type
        ]);

        return response()->json([
            'message' => 'Correction created for review',
            'correction' => $correction
        ], 201);
    }

    /**
     * Apply correction to business training data
     */
    private function applyCorrectionToTraining(MessageCorrection $correction, ?string $reviewerQuestion = null): void
    {
        $business = $correction->originalMessage->conversation->channel->business;
        if (!$business) {
            return;
        }

        switch ($correction->learning_type) {
            case 'faq':
                $this->addToFAQs($business, $correction, $reviewerQuestion ?? '');
                break;
            case 'tone':
                $this->updateToneStyle($business, $correction);
                break;
            case 'knowledge':
                $this->addToKnowledgeBase($business, $correction);
                break;
            default:
                // Store as general learning example
                $this->storeAsLearningExample($business, $correction);
        }
    }

    private function addToFAQs(BusinessProfile $business, MessageCorrection $correction, string $reviewerQuestion): void
    {
        $faqs = $business->faqs ?? [];
        $redactor = app(\App\Services\PiiRedactor::class);
        $question = $redactor->redact($reviewerQuestion)['text'];
        $answer = $redactor->redact((string) $correction->human_correction)['text'];

        // Check if similar FAQ exists
        $similarExists = collect($faqs)->contains(function ($faq) use ($question) {
            return similar_text($faq['question'] ?? '', $question) > 70;
        });

        if (!$similarExists) {
            $faqs[] = [
                'question' => $question,
                'answer' => $answer
            ];
            $business->faqs = $faqs;
            $business->save();
        }
    }

    private function updateToneStyle(BusinessProfile $business, MessageCorrection $correction): void
    {
        // Update tone style based on human preference
        $currentTone = $business->ai_tone_style ?? [
            'tone' => 'friendly',
            'formality' => 'casual',
            'focus' => 'support'
        ];

        // This would be enhanced with ML to learn from multiple corrections
        $business->ai_tone_style = $currentTone;
        $business->save();
    }

    private function addToKnowledgeBase(BusinessProfile $business, MessageCorrection $correction): void
    {
        $redactor = app(\App\Services\PiiRedactor::class);
        $extractedText = $redactor->redact("Question: {$correction->originalMessage->content}\n\nAnswer: {$correction->human_correction}")['text'];
        $ingestion = app(KnowledgeFileIngestionService::class)->ingest(
            $business,
            'reviewed_correction_' . $correction->id . '.txt',
            'text/plain',
            $extractedText,
            strlen($extractedText)
        );
        $knowledgeFile = $ingestion['file'];

        $conversation = $correction->originalMessage->conversation;
        $botId = $conversation?->bot_id;
        if ($botId && $conversation?->channel_id) {
            $botBelongsToBusiness = Bot::query()
                ->whereKey($botId)
                ->where('business_profile_id', $business->id)
                ->exists();

            if ($botBelongsToBusiness) {
                BotKnowledgeAssignment::firstOrCreate([
                    'bot_id' => $botId,
                    'business_knowledge_file_id' => $knowledgeFile->id,
                    'channel_id' => $conversation->channel_id,
                ]);
            }
        }

        Log::info('Correction linked to knowledge base', [
            'knowledge_file_id' => $knowledgeFile->id,
            'business_id' => $business->id,
            'duplicate' => $ingestion['duplicate'],
        ]);
    }

    private function storeAsLearningExample(BusinessProfile $business, MessageCorrection $correction): void
    {
        // Store as learning example for future model fine-tuning
        // This would be expanded with proper ML infrastructure
        Log::info('Stored as learning example', [
            'correction_id' => $correction->id,
            'business_id' => $business->id
        ]);
    }

    /**
     * Get real AI performance stats from messages + conversations.
     *
     * These numbers change as the AI handles real conversations. Supports a
     * validated date-range `preset` and an optional `business_id` (the user may
     * only request stats for a business they own). All aggregation happens in
     * the database via TrainingStatsService.
     */
    public function getStats(Request $request)
    {
        $validated = $request->validate([
            'preset'      => 'sometimes|string|in:today,last_7_days,this_month,last_30_days,all_time',
        ]);

        $preset = $validated['preset'] ?? 'last_30_days';
        $business = app(\App\Services\ActiveBusiness::class)->resolve($request, permission: 'read');

        $stats = app(TrainingStatsService::class)->statistics((int) $business->id, $preset);

        return response()->json($stats, 200, [], JSON_PRESERVE_ZERO_FRACTION);
    }
}
