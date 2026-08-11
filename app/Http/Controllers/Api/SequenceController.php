<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Sequence;
use App\Models\SequenceStep;
use App\Models\SequenceUser;
use App\Models\Conversation;
use App\Jobs\ProcessSequenceStep;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class SequenceController extends Controller
{
    /**
     * Get all sequences for a business
     */
    public function index(Request $request, $businessId)
    {
        $sequences = Sequence::where('business_id', $businessId)
            ->with(['steps'])
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json($sequences);
    }

    /**
     * Create a new sequence
     */
    public function store(Request $request, $businessId)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'trigger_type' => 'required|in:new_user,tag_added,no_reply,manual',
            'trigger_config' => 'nullable|array',
            'steps' => 'required|array',
            'steps.*.message' => 'required|string',
            'steps.*.delay_hours' => 'required|integer|min:0',
        ]);

        $sequence = Sequence::create([
            'business_id' => $businessId,
            'name' => $request->name,
            'trigger_type' => $request->trigger_type,
            'trigger_config' => $request->trigger_config,
            'is_active' => true,
        ]);

        // Create steps
        foreach ($request->steps as $index => $stepData) {
            SequenceStep::create([
                'sequence_id' => $sequence->id,
                'step_order' => $index + 1,
                'message' => $stepData['message'],
                'delay_hours' => $stepData['delay_hours'],
                'is_active' => true,
            ]);
        }

        return response()->json(['success' => true, 'sequence' => $sequence->load('steps')]);
    }

    /**
     * Update a sequence
     */
    public function update(Request $request, $businessId, $sequenceId)
    {
        $request->validate([
            'name' => 'sometimes|string|max:255',
            'is_active' => 'sometimes|boolean',
            'steps' => 'sometimes|array',
            'steps.*.message' => 'required|string',
            'steps.*.delay_hours' => 'required|integer|min:0',
        ]);

        $sequence = Sequence::where('business_id', $businessId)
            ->findOrFail($sequenceId);

        $sequence->update($request->only(['name', 'is_active']));

        // Update steps if provided
        if ($request->has('steps')) {
            // Delete existing steps
            $sequence->steps()->delete();

            // Create new steps
            foreach ($request->steps as $index => $stepData) {
                SequenceStep::create([
                    'sequence_id' => $sequence->id,
                    'step_order' => $index + 1,
                    'message' => $stepData['message'],
                    'delay_hours' => $stepData['delay_hours'],
                    'is_active' => true,
                ]);
            }
        }

        return response()->json(['success' => true, 'sequence' => $sequence->load('steps')]);
    }

    /**
     * Delete a sequence
     */
    public function destroy(Request $request, $businessId, $sequenceId)
    {
        $sequence = Sequence::where('business_id', $businessId)
            ->findOrFail($sequenceId);

        $sequence->users()->delete();
        $sequence->steps()->delete();
        $sequence->delete();

        return response()->json(['success' => true]);
    }

    /**
     * Manually enroll a conversation in a sequence
     */
    public function enroll(Request $request, $businessId, $sequenceId)
    {
        $request->validate([
            'conversation_id' => 'required|exists:conversations,id',
        ]);

        $sequence = Sequence::where('business_id', $businessId)
            ->findOrFail($sequenceId);

        $conversation = Conversation::find($request->conversation_id);

        // Check if already enrolled
        $existingEnrollment = SequenceUser::where('sequence_id', $sequence->id)
            ->where('conversation_id', $conversation->id)
            ->first();

        if ($existingEnrollment) {
            return response()->json(['error' => 'Conversation already enrolled in this sequence'], 400);
        }

        // Create enrollment
        $sequenceUser = SequenceUser::create([
            'sequence_id' => $sequence->id,
            'conversation_id' => $conversation->id,
            'current_step' => 0,
            'status' => 'active',
            'started_at' => now(),
        ]);

        // Trigger first step immediately
        ProcessSequenceStep::dispatch($sequenceUser->id);

        return response()->json(['success' => true, 'sequence_user' => $sequenceUser]);
    }

    /**
     * Get sequence enrollments
     */
    public function enrollments(Request $request, $businessId, $sequenceId)
    {
        $sequence = Sequence::where('business_id', $businessId)
            ->findOrFail($sequenceId);

        $enrollments = $sequence->users()
            ->with(['conversation'])
            ->orderBy('created_at', 'desc')
            ->paginate(50);

        return response()->json($enrollments);
    }
}
