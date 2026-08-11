<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AiActionLog;
use Illuminate\Http\Request;

class AiActionsController extends Controller
{
    /**
     * Get AI actions for a business
     */
    public function index(Request $request, $businessId)
    {
        $actions = AiActionLog::whereHas('conversation', function ($q) use ($businessId) {
                $q->where('business_id', $businessId);
            })
            ->with(['conversation', 'message', 'approvedBy'])
            ->orderBy('created_at', 'desc')
            ->paginate(50);

        return response()->json($actions);
    }

    /**
     * Get pending actions awaiting approval
     */
    public function pending(Request $request, $businessId)
    {
        $actions = AiActionLog::whereHas('conversation', function ($q) use ($businessId) {
                $q->where('business_id', $businessId);
            })
            ->where('status', 'pending')
            ->with(['conversation', 'message'])
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json(['actions' => $actions]);
    }

    /**
     * Approve an action
     */
    public function approve(Request $request, $businessId, $actionId)
    {
        $action = AiActionLog::whereHas('conversation', function ($q) use ($businessId) {
                $q->where('business_id', $businessId);
            })
            ->findOrFail($actionId);

        if ($action->status !== 'pending') {
            return response()->json(['error' => 'Action is not pending'], 400);
        }

        $action->update([
            'status' => 'approved',
            'approved_by' => auth()->id(),
            'approved_at' => now(),
        ]);

        // Execute the approved action
        $actionExecutor = new \App\Services\ActionExecutor();
        $result = $actionExecutor->executeAction(
            $action->action_payload,
            $action->conversation_id,
            $action->message_id
        );

        $action->update([
            'status' => 'executed',
            'result' => $result,
            'executed_at' => now(),
        ]);

        return response()->json(['success' => true, 'result' => $result]);
    }

    /**
     * Reject an action
     */
    public function reject(Request $request, $businessId, $actionId)
    {
        $action = AiActionLog::whereHas('conversation', function ($q) use ($businessId) {
                $q->where('business_id', $businessId);
            })
            ->findOrFail($actionId);

        if ($action->status !== 'pending') {
            return response()->json(['error' => 'Action is not pending'], 400);
        }

        $action->update([
            'status' => 'rejected',
            'approved_by' => auth()->id(),
            'approved_at' => now(),
        ]);

        return response()->json(['success' => true]);
    }

    /**
     * Get action statistics
     */
    public function statistics(Request $request, $businessId)
    {
        $total = AiActionLog::whereHas('conversation', function ($q) use ($businessId) {
                $q->where('business_id', $businessId);
            })->count();

        $executed = AiActionLog::whereHas('conversation', function ($q) use ($businessId) {
                $q->where('business_id', $businessId);
            })->where('status', 'executed')->count();

        $failed = AiActionLog::whereHas('conversation', function ($q) use ($businessId) {
                $q->where('business_id', $businessId);
            })->where('status', 'failed')->count();

        $pending = AiActionLog::whereHas('conversation', function ($q) use ($businessId) {
                $q->where('business_id', $businessId);
            })->where('status', 'pending')->count();

        return response()->json([
            'total' => $total,
            'executed' => $executed,
            'failed' => $failed,
            'pending' => $pending,
            'success_rate' => $total > 0 ? round(($executed / $total) * 100, 2) : 0,
        ]);
    }
}
