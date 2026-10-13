<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Sequence;
use App\Models\SequenceEnrollment;
use App\Models\SequenceStepExecution;
use App\Services\SequenceService;
use App\Services\SequenceEnrollmentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class SequenceController extends Controller
{
    private SequenceService $sequenceService;
    private SequenceEnrollmentService $enrollmentService;

    public function __construct(
        SequenceService $sequenceService,
        SequenceEnrollmentService $enrollmentService
    ) {
        $this->sequenceService = $sequenceService;
        $this->enrollmentService = $enrollmentService;
    }

    /**
     * Resolve the business profile for the current request.
     */
    private function getResolvedBusinessProfile(Request $request): \App\Models\BusinessProfile
    {
        $permission = $request->isMethod('GET') ? 'automation_read' : 'automation_write';
        return app(\App\Services\ActiveBusiness::class)->resolve($request, permission: $permission);
    }

    public function index(Request $request)
    {
        $business = $this->getResolvedBusinessProfile($request);

        $filters = [
            'status' => $request->input('status'),
            'channel' => $request->input('channel'),
            'trigger_type' => $request->input('trigger_type'),
            'bot_id' => $request->input('bot_id'),
        ];

        $query = Sequence::forBusiness($business->id);

        // Apply bot filter if provided
        if (!empty($filters['bot_id'])) {
            $query->where('bot_id', (int) $filters['bot_id']);
        }

        // Apply other filters
        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (!empty($filters['channel'])) {
            $query->where('channel', $filters['channel']);
        }
        if (!empty($filters['trigger_type'])) {
            $query->where('trigger_type', $filters['trigger_type']);
        }

        $sequences = $query->with('steps')->orderBy('created_at', 'desc')->get();

        return response()->json([
            'data' => $sequences,
        ]);
    }

    public function store(Request $request)
    {
        $businessId = $this->getResolvedBusinessProfile($request)->id;

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'trigger_type' => 'nullable|in:new_user,tag_added,no_reply,manual,order_created',
            'trigger_config' => 'nullable|array',
            'channel' => 'nullable|in:whatsapp,telegram,email',
            'settings' => 'nullable|array',
            'settings.allow_reentry' => 'sometimes|boolean',
            'settings.stop_on_reply' => 'sometimes|boolean',
            'settings.target_channels' => 'sometimes|array|min:1',
            'settings.target_channels.*' => 'string|in:whatsapp,telegram,email,instagram,facebook,messenger',
            'steps' => 'nullable|array',
            'bot_id' => 'nullable|integer|exists:bots,id',
        ]);

        try {
            $sequence = $this->sequenceService->createSequence($validated, $businessId);
            return response()->json(['data' => $sequence], 201);
        } catch (\Illuminate\Validation\ValidationException $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::error('Failed to create sequence', ['exception_type' => get_class($e)]);
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function show(Request $request, $id)
    {
        $business = $this->getResolvedBusinessProfile($request);

        $sequence = Sequence::forBusiness($business->id)->with('steps')->find($id);

        if (!$sequence) {
            return response()->json(['error' => 'Sequence not found'], 404);
        }

        return response()->json(['data' => $sequence]);
    }

    public function update(Request $request, $id)
    {
        $business = $this->getResolvedBusinessProfile($request);

        $sequence = Sequence::forBusiness($business->id)->find($id);

        if (!$sequence) {
            return response()->json(['error' => 'Sequence not found'], 404);
        }

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'description' => 'nullable|string',
            'trigger_type' => 'nullable|in:new_user,tag_added,no_reply,manual,order_created',
            'trigger_config' => 'nullable|array',
            'channel' => 'nullable|in:whatsapp,telegram,email',
            'settings' => 'nullable|array',
            'settings.allow_reentry' => 'sometimes|boolean',
            'settings.stop_on_reply' => 'sometimes|boolean',
            'settings.target_channels' => 'sometimes|array|min:1',
            'settings.target_channels.*' => 'string|in:whatsapp,telegram,email,instagram,facebook,messenger',
            'steps' => 'nullable|array',
        ]);

        try {
            $sequence = $this->sequenceService->updateSequence($sequence, $validated);
            return response()->json(['data' => $sequence]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::error('Failed to update sequence', ['exception_type' => get_class($e)]);
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function destroy(Request $request, $id)
    {
        $business = $this->getResolvedBusinessProfile($request);

        $sequence = Sequence::forBusiness($business->id)->find($id);

        if (!$sequence) {
            return response()->json(['error' => 'Sequence not found'], 404);
        }

        try {
            $this->sequenceService->deleteSequence($sequence);
            return response()->json(['success' => true]);
        } catch (\Exception $e) {
            Log::error('Failed to delete sequence', ['exception_type' => get_class($e)]);
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function activate(Request $request, $id)
    {
        $business = $this->getResolvedBusinessProfile($request);

        $sequence = Sequence::forBusiness($business->id)->find($id);

        if (!$sequence) {
            return response()->json(['error' => 'Sequence not found'], 404);
        }

        try {
            $sequence = $this->sequenceService->activateSequence($sequence);
            app(\App\Services\AutomationRunControl::class)->audit($this->getResolvedBusinessProfile($request), 'automation.sequence_resumed', $sequence::class, (int) $sequence->id);
            return response()->json(['data' => $sequence]);
        } catch (\Exception $e) {
            Log::error('Failed to activate sequence', ['exception_type' => get_class($e)]);
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function pause(Request $request, $id)
    {
        $business = $this->getResolvedBusinessProfile($request);

        $sequence = Sequence::forBusiness($business->id)->find($id);

        if (!$sequence) {
            return response()->json(['error' => 'Sequence not found'], 404);
        }

        try {
            $sequence = $this->sequenceService->pauseSequence($sequence);
            app(\App\Services\AutomationRunControl::class)->audit($this->getResolvedBusinessProfile($request), 'automation.sequence_paused', $sequence::class, (int) $sequence->id);
            return response()->json(['data' => $sequence]);
        } catch (\Exception $e) {
            Log::error('Failed to pause sequence', ['exception_type' => get_class($e)]);
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function duplicate(Request $request, $id)
    {
        $business = $this->getResolvedBusinessProfile($request);

        $sequence = Sequence::forBusiness($business->id)->find($id);

        if (!$sequence) {
            return response()->json(['error' => 'Sequence not found'], 404);
        }

        try {
            $newSequence = $this->sequenceService->duplicateSequence($sequence);
            return response()->json(['data' => $newSequence], 201);
        } catch (\Exception $e) {
            Log::error('Failed to duplicate sequence', ['exception_type' => get_class($e)]);
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function analytics(Request $request, $id)
    {
        $business = $this->getResolvedBusinessProfile($request);

        $sequence = Sequence::forBusiness($business->id)->find($id);

        if (!$sequence) {
            return response()->json(['error' => 'Sequence not found'], 404);
        }

        try {
            $analytics = $this->sequenceService->getSequenceAnalytics($sequence);
            return response()->json(['data' => $analytics]);
        } catch (\Exception $e) {
            Log::error('Failed to get sequence analytics', ['exception_type' => get_class($e)]);
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function enrollments(Request $request, $id)
    {
        $business = $this->getResolvedBusinessProfile($request);

        $sequence = Sequence::forBusiness($business->id)->find($id);

        if (!$sequence) {
            return response()->json(['error' => 'Sequence not found'], 404);
        }

        $filters = [
            'status' => $request->input('status'),
        ];

        try {
            $enrollments = $this->enrollmentService->getEnrollmentsForSequence($sequence, $filters);
            return response()->json(['data' => $enrollments]);
        } catch (\Exception $e) {
            Log::error('Failed to get sequence enrollments', ['exception_type' => get_class($e)]);
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function enroll(Request $request, $id)
    {
        $user = Auth::user();
        $business = $this->getResolvedBusinessProfile($request);

        $sequence = Sequence::forBusiness($business->id)->find($id);

        if (!$sequence) {
            return response()->json(['error' => 'Sequence not found'], 404);
        }

        $validated = $request->validate([
            'conversation_id' => 'required|integer|exists:conversations,id',
            'start_step' => 'nullable|integer|min:0',
        ]);

        try {
            $conversation = \App\Models\Conversation::find($validated['conversation_id']);
            
            if (!$conversation || (int) $conversation->business_id !== (int) $business->id) {
                return response()->json(['error' => 'Conversation not found or access denied'], 404);
            }

            $enrollment = $this->enrollmentService->enrollConversation(
                $sequence,
                $conversation,
                $validated['start_step'] ?? 0
            );

            return response()->json(['data' => $enrollment], 201);
        } catch (\Illuminate\Validation\ValidationException $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::error('Failed to enroll conversation', ['exception_type' => get_class($e)]);
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function unenroll(Request $request, $id)
    {
        $business = $this->getResolvedBusinessProfile($request);

        $sequence = Sequence::forBusiness($business->id)->find($id);

        if (!$sequence) {
            return response()->json(['error' => 'Sequence not found'], 404);
        }

        $validated = $request->validate([
            'enrollment_id' => 'required|integer|exists:sequence_enrollments,id',
            'reason' => 'nullable|string',
        ]);

        try {
            $enrollment = SequenceEnrollment::find($validated['enrollment_id']);

            if (!$enrollment || $enrollment->sequence_id !== $sequence->id) {
                return response()->json(['error' => 'Enrollment not found or access denied'], 404);
            }

            $this->enrollmentService->unenrollConversation($enrollment, $validated['reason'] ?? 'manual');
            return response()->json(['success' => true]);
        } catch (\Exception $e) {
            Log::error('Failed to unenroll conversation', ['exception_type' => get_class($e)]);
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function queueMetrics()
    {
        abort_unless(Auth::user()?->is_admin, 403);

        $pendingExecutions = SequenceStepExecution::pending()->count();
        $processingExecutions = SequenceStepExecution::processing()->count();
        $failedExecutions = SequenceStepExecution::failed()->count();
        $executedExecutions = SequenceStepExecution::executed()->count();

        $activeEnrollments = SequenceEnrollment::where('status', 'active')->count();
        $completedEnrollments = SequenceEnrollment::where('status', 'completed')->count();
        $stoppedEnrollments = SequenceEnrollment::where('status', 'stopped')->count();
        $failedEnrollments = SequenceEnrollment::where('status', 'failed')->count();

        $stuckExecutions = SequenceStepExecution::processing()
            ->where('updated_at', '<', now()->subHour())
            ->count();

        $overdueExecutions = SequenceStepExecution::pending()
            ->where('scheduled_at', '<', now())
            ->count();

        $healthScore = 100;
        if ($failedExecutions > 10) $healthScore -= 20;
        if ($stuckExecutions > 0) $healthScore -= 30;
        if ($overdueExecutions > 5) $healthScore -= 15;

        return response()->json([
            'executions' => [
                'pending' => $pendingExecutions,
                'processing' => $processingExecutions,
                'executed' => $executedExecutions,
                'failed' => $failedExecutions,
            ],
            'enrollments' => [
                'active' => $activeEnrollments,
                'completed' => $completedEnrollments,
                'stopped' => $stoppedEnrollments,
                'failed' => $failedEnrollments,
            ],
            'health' => [
                'score' => max(0, $healthScore),
                'stuck_executions' => $stuckExecutions,
                'overdue_executions' => $overdueExecutions,
            ],
        ]);
    }

    public function resetStuckExecutions()
    {
        abort_unless(Auth::user()?->is_admin, 403);

        $resetCount = SequenceStepExecution::processing()
            ->where('updated_at', '<', now()->subHour())
            ->update(['status' => 'pending']);

        return response()->json([
            'success' => true,
            'reset_count' => $resetCount,
        ]);
    }
}
