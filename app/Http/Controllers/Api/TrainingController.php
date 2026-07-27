<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MessageCorrection;
use App\Models\BusinessProfile;
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
        $user = Auth::user();
        
        $corrections = MessageCorrection::with(['originalMessage.conversation', 'approvedBy'])
            ->whereHas('originalMessage.conversation.channel', function ($query) use ($user) {
                $query->where('user_id', $user->id);
            })
            ->orderBy('created_at', 'desc')
            ->get();

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
        $correction = MessageCorrection::with('originalMessage.conversation.channel')->find($id);

        if (!$correction) {
            return response()->json(['error' => 'Correction not found'], 404);
        }

        // Verify ownership
        if ($correction->originalMessage->conversation->channel->user_id !== $user->id) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $correction->update([
            'approved' => true,
            'approved_by' => $user->id,
            'approved_at' => now(),
        ]);

        // Apply the correction to training data
        $this->applyCorrectionToTraining($correction);

        Log::info('Message correction approved', [
            'correction_id' => $id,
            'user_id' => $user->id,
            'learning_type' => $correction->learning_type
        ]);

        return response()->json([
            'message' => 'Correction approved and added to training',
            'correction' => $correction->fresh()
        ]);
    }

    /**
     * Reject a correction
     */
    public function rejectCorrection(Request $request, $id)
    {
        $user = Auth::user();
        $correction = MessageCorrection::with('originalMessage.conversation.channel')->find($id);

        if (!$correction) {
            return response()->json(['error' => 'Correction not found'], 404);
        }

        // Verify ownership
        if ($correction->originalMessage->conversation->channel->user_id !== $user->id) {
            return response()->json(['error' => 'Unauthorized'], 403);
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
        
        $validated = $request->validate([
            'original_message_id' => 'required|exists:messages,id',
            'ai_draft' => 'required|string',
            'human_correction' => 'required|string',
            'learning_type' => 'nullable|in:faq,tone,knowledge',
        ]);

        // Verify ownership
        $originalMessage = \App\Models\Message::with('conversation.channel')->find($validated['original_message_id']);
        if (!$originalMessage || $originalMessage->conversation->channel->user_id !== $user->id) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

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
    private function applyCorrectionToTraining(MessageCorrection $correction): void
    {
        $business = $correction->originalMessage->conversation->channel->business;
        if (!$business) {
            return;
        }

        switch ($correction->learning_type) {
            case 'faq':
                $this->addToFAQs($business, $correction);
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

    private function addToFAQs(BusinessProfile $business, MessageCorrection $correction): void
    {
        $faqs = $business->faqs ?? [];
        $question = $correction->originalMessage->content;
        $answer = $correction->human_correction;

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
        // Create a knowledge file from the correction
        $knowledgeFile = \App\Models\BusinessKnowledgeFile::create([
            'business_id' => $business->id,
            'filename' => 'learning_correction_' . now()->format('Y-m-d_His') . '. md5($correction->human_correction) . '.txt',
            'file_type' => 'text/plain',
            'file_size' => strlen($correction->human_correction),
            'extracted_text' => "Question: {$correction->originalMessage->content}\n\nAnswer: {$correction->human_correction}",
        ]);

        Log::info('Added correction to knowledge base', [
            'knowledge_file_id' => $knowledgeFile->id,
            'business_id' => $business->id
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
     * Get training statistics
     */
    public function getStats(Request $request)
    {
        $user = Auth::user();
        
        $totalCorrections = MessageCorrection::whereHas('originalMessage.conversation.channel', function ($query) use ($user) {
            $query->where('user_id', $user->id);
        })->count();

        $approvedCorrections = MessageCorrection::whereHas('originalMessage.conversation.channel', function ($query) use ($user) {
            $query->where('user_id', $user->id);
        })->where('approved', true)->count();

        $pendingCorrections = $totalCorrections - $approvedCorrections;

        $learningTypes = MessageCorrection::whereHas('originalMessage.conversation.channel', function ($query) use ($user) {
            $query->where('user_id', $user->id);
        })
        ->selectRaw('learning_type, COUNT(*) as count')
        ->groupBy('learning_type')
        ->pluck('count', 'learning_type')
        ->toArray();

        return response()->json([
            'total_corrections' => $totalCorrections,
            'approved_corrections' => $approvedCorrections,
            'pending_review' => $pendingCorrections,
            'learning_types' => $learningTypes,
        ]);
    }
}