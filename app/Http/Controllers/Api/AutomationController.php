<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AutomationWorkflow;
use App\Models\BusinessHour;
use App\Models\AutoMessage;
use App\Models\BusinessProfile;
use App\Models\Conversation;
use App\Models\WorkflowExecution;
use App\Services\AutomationEngine;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class AutomationController extends Controller
{
    /**
     * Get business hours for a business
     */
    public function getBusinessHours(Request $request, $businessId)
    {
        $business = BusinessProfile::where('user_id', Auth::id())
            ->findOrFail($businessId);

        $hours = BusinessHour::where('business_id', $businessId)
            ->orderBy('day_of_week')
            ->get();

        return response()->json($hours);
    }

    /**
     * Update business hours
     */
    public function updateBusinessHours(Request $request, $businessId)
    {
        $request->validate([
            'hours' => 'required|array',
            'hours.*.day_of_week' => 'required|integer|min:0|max:6',
            'hours.*.start_time' => 'required|date_format:H:i:s',
            'hours.*.end_time' => 'required|date_format:H:i:s',
            'hours.*.is_active' => 'boolean',
        ]);

        $business = BusinessProfile::where('user_id', Auth::id())
            ->findOrFail($businessId);

        // Delete existing hours
        BusinessHour::where('business_id', $businessId)->delete();

        // Create new hours
        foreach ($request->hours as $hourData) {
            BusinessHour::create([
                'business_id' => $businessId,
                'day_of_week' => $hourData['day_of_week'],
                'start_time' => $hourData['start_time'],
                'end_time' => $hourData['end_time'],
                'is_active' => $hourData['is_active'] ?? true,
            ]);
        }

        return response()->json(['success' => true]);
    }

    /**
     * Get auto messages
     */
    public function getAutoMessages(Request $request, $businessId)
    {
        $business = BusinessProfile::where('user_id', Auth::id())
            ->findOrFail($businessId);

        $messages = AutoMessage::where('business_id', $businessId)->get();

        return response()->json($messages);
    }

    /**
     * Update auto message
     */
    public function updateAutoMessage(Request $request, $businessId)
    {
        $request->validate([
            'type' => 'required|in:away,holiday,welcome',
            'message' => 'required|string',
            'is_enabled' => 'boolean',
            'timezone' => 'string',
        ]);

        $business = BusinessProfile::where('user_id', Auth::id())
            ->findOrFail($businessId);

        $autoMessage = AutoMessage::updateOrCreate(
            [
                'business_id' => $businessId,
                'type' => $request->type,
            ],
            [
                'message' => $request->message,
                'is_enabled' => $request->is_enabled ?? true,
                'timezone' => $request->timezone ?? 'UTC',
            ]
        );

        return response()->json(['success' => true, 'auto_message' => $autoMessage]);
    }

    /**
     * Update business timezone
     */
    public function updateTimezone(Request $request, $businessId)
    {
        $request->validate([
            'timezone' => 'required|string',
        ]);

        $business = BusinessProfile::where('user_id', Auth::id())
            ->findOrFail($businessId);

        $business->update(['timezone' => $request->timezone]);

        return response()->json(['success' => true]);
    }

    /**
     * Get comment automation settings for a business
     */
    public function getCommentSettings(Request $request, $businessId)
    {
        $business = BusinessProfile::where('user_id', Auth::id())
            ->findOrFail($businessId);

        return response()->json([
            'comment_automation_enabled' => $business->comment_automation_enabled,
            'instagram_comments_enabled' => $business->instagram_comments_enabled,
            'facebook_comments_enabled' => $business->facebook_comments_enabled,
            'reply_mode' => $business->reply_mode,
            'confidence_threshold' => $business->confidence_threshold,
            'reply_language' => $business->reply_language,
            'max_reply_length' => $business->max_reply_length,
            'use_knowledge' => $business->use_knowledge,
            'use_products' => $business->use_products,
            'use_prices' => $business->use_prices,
            'use_inventory' => $business->use_inventory,
            'use_orders' => $business->use_orders,
            'use_shipping' => $business->use_shipping,
            'use_policies' => $business->use_policies,
            'ignore_spam' => $business->ignore_spam,
            'ignore_offensive' => $business->ignore_offensive,
            'ignore_competitors' => $business->ignore_competitors,
            'blocked_keywords' => $business->blocked_keywords,
            'emoji_enabled' => $business->emoji_enabled,
        ]);
    }

    /**
     * Update comment automation settings for a business
     */
    public function updateCommentSettings(Request $request, $businessId)
    {
        $request->validate([
            'comment_automation_enabled' => 'boolean',
            'instagram_comments_enabled' => 'boolean',
            'facebook_comments_enabled' => 'boolean',
            'reply_mode' => 'in:public_comment,public_reply_private_message,private_message',
            'confidence_threshold' => 'integer|min:0|max:100',
            'reply_language' => 'in:automatic,arabic,english,same_as_customer',
            'max_reply_length' => 'integer|min:1',
            'use_knowledge' => 'boolean',
            'use_products' => 'boolean',
            'use_prices' => 'boolean',
            'use_inventory' => 'boolean',
            'use_orders' => 'boolean',
            'use_shipping' => 'boolean',
            'use_policies' => 'boolean',
            'ignore_spam' => 'boolean',
            'ignore_offensive' => 'boolean',
            'ignore_competitors' => 'boolean',
            'blocked_keywords' => 'nullable|string', // JSON array or comma-separated string
            'emoji_enabled' => 'boolean',
        ]);

        $business = BusinessProfile::where('user_id', Auth::id())
            ->findOrFail($businessId);

        $business->update($request->only([
            'comment_automation_enabled',
            'instagram_comments_enabled',
            'facebook_comments_enabled',
            'reply_mode',
            'confidence_threshold',
            'reply_language',
            'max_reply_length',
            'use_knowledge',
            'use_products',
            'use_prices',
            'use_inventory',
            'use_orders',
            'use_shipping',
            'use_policies',
            'ignore_spam',
            'ignore_offensive',
            'ignore_competitors',
            'blocked_keywords',
            'emoji_enabled',
        ]));

        return response()->json(['success' => true]);
    }

    // ── WORKFLOW CRUD METHODS ───────────────────────────────────────────────────

    /**
     * Get workflows for the authenticated user's businesses
     */
    public function index(Request $request)
    {
        $user = Auth::user();

        // Get all business IDs the user has access to (as owner or team member)
        $businessIds = BusinessProfile::where('user_id', $user->id)
            ->pluck('id')
            ->merge(
                \App\Models\TeamMember::where('user_id', $user->id)
                    ->where('is_active', true)
                    ->pluck('business_id')
            )
            ->unique();

        $workflows = AutomationWorkflow::whereIn('business_id', $businessIds)
            ->with('business')
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($workflow) {
                return [
                    'id' => $workflow->id,
                    'name' => $workflow->name,
                    'description' => $workflow->description,
                    'is_active' => $workflow->active,
                    'trigger' => $workflow->trigger_config,
                    'conditions' => $workflow->conditions,
                    'actions' => $workflow->actions_config,
                    'execution_count' => $workflow->executions_count,
                    'last_executed_at' => $workflow->last_executed_at?->toISOString(),
                    'created_at' => $workflow->created_at->toISOString(),
                ];
            });

        return response()->json($workflows);
    }

    /**
     * Store a new workflow
     */
    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'trigger' => 'required|array',
            'trigger.type' => 'required|string',
            'conditions' => 'nullable|array',
            'actions' => 'required|array|min:1',
        ]);

        $user = Auth::user();

        // Resolve business_id from request or use user's primary business
        $businessId = $request->business_id;
        if (!$businessId) {
            $business = BusinessProfile::where('user_id', $user->id)->first();
            if (!$business) {
                return response()->json(['error' => 'No business found for user'], 400);
            }
            $businessId = $business->id;
        }

        // Verify user has access to this business
        $hasAccess = BusinessProfile::where('id', $businessId)
            ->where('user_id', $user->id)
            ->exists() ||
            \App\Models\TeamMember::where('business_id', $businessId)
                ->where('user_id', $user->id)
                ->where('is_active', true)
                ->exists();

        if (!$hasAccess) {
            return response()->json(['error' => 'Unauthorized access to business'], 403);
        }

        $workflow = AutomationWorkflow::create([
            'user_id' => $user->id,
            'business_id' => $businessId,
            'name' => $request->name,
            'description' => $request->description,
            'active' => true,
            'trigger_config' => $request->trigger,
            'conditions' => $request->conditions,
            'actions_config' => $request->actions,
            'executions_count' => 0,
        ]);

        return response()->json([
            'id' => $workflow->id,
            'name' => $workflow->name,
            'description' => $workflow->description,
            'is_active' => $workflow->active,
            'trigger' => $workflow->trigger_config,
            'conditions' => $workflow->conditions,
            'actions' => $workflow->actions_config,
            'execution_count' => $workflow->executions_count,
            'last_executed_at' => $workflow->last_executed_at?->toISOString(),
            'created_at' => $workflow->created_at->toISOString(),
        ], 201);
    }

    /**
     * Get a specific workflow
     */
    public function show(Request $request, $id)
    {
        $user = Auth::user();

        $businessIds = BusinessProfile::where('user_id', $user->id)
            ->pluck('id')
            ->merge(
                \App\Models\TeamMember::where('user_id', $user->id)
                    ->where('is_active', true)
                    ->pluck('business_id')
            )
            ->unique();

        $workflow = AutomationWorkflow::whereIn('business_id', $businessIds)
            ->findOrFail($id);

        return response()->json([
            'id' => $workflow->id,
            'name' => $workflow->name,
            'description' => $workflow->description,
            'is_active' => $workflow->active,
            'trigger' => $workflow->trigger_config,
            'conditions' => $workflow->conditions,
            'actions' => $workflow->actions_config,
            'execution_count' => $workflow->executions_count,
            'last_executed_at' => $workflow->last_executed_at?->toISOString(),
            'created_at' => $workflow->created_at->toISOString(),
        ]);
    }

    /**
     * Update a workflow
     */
    public function update(Request $request, $id)
    {
        $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'description' => 'nullable|string',
            'trigger' => 'sometimes|required|array',
            'trigger.type' => 'sometimes|required|string',
            'conditions' => 'nullable|array',
            'actions' => 'sometimes|required|array|min:1',
        ]);

        $user = Auth::user();

        $businessIds = BusinessProfile::where('user_id', $user->id)
            ->pluck('id')
            ->merge(
                \App\Models\TeamMember::where('user_id', $user->id)
                    ->where('is_active', true)
                    ->pluck('business_id')
            )
            ->unique();

        $workflow = AutomationWorkflow::whereIn('business_id', $businessIds)
            ->findOrFail($id);

        $workflow->update([
            'name' => $request->name ?? $workflow->name,
            'description' => $request->description ?? $workflow->description,
            'trigger_config' => $request->trigger ?? $workflow->trigger_config,
            'conditions' => $request->conditions ?? $workflow->conditions,
            'actions_config' => $request->actions ?? $workflow->actions_config,
        ]);

        return response()->json([
            'id' => $workflow->id,
            'name' => $workflow->name,
            'description' => $workflow->description,
            'is_active' => $workflow->active,
            'trigger' => $workflow->trigger_config,
            'conditions' => $workflow->conditions,
            'actions' => $workflow->actions_config,
            'execution_count' => $workflow->executions_count,
            'last_executed_at' => $workflow->last_executed_at?->toISOString(),
            'created_at' => $workflow->created_at->toISOString(),
        ]);
    }

    /**
     * Delete a workflow
     */
    public function destroy(Request $request, $id)
    {
        $user = Auth::user();

        $businessIds = BusinessProfile::where('user_id', $user->id)
            ->pluck('id')
            ->merge(
                \App\Models\TeamMember::where('user_id', $user->id)
                    ->where('is_active', true)
                    ->pluck('business_id')
            )
            ->unique();

        $workflow = AutomationWorkflow::whereIn('business_id', $businessIds)
            ->findOrFail($id);

        $workflow->delete();

        return response()->json(['success' => true]);
    }

    /**
     * Toggle workflow active status
     */
    public function toggle(Request $request, $id)
    {
        $user = Auth::user();

        $businessIds = BusinessProfile::where('user_id', $user->id)
            ->pluck('id')
            ->merge(
                \App\Models\TeamMember::where('user_id', $user->id)
                    ->where('is_active', true)
                    ->pluck('business_id')
            )
            ->unique();

        $workflow = AutomationWorkflow::whereIn('business_id', $businessIds)
            ->findOrFail($id);

        $workflow->update(['active' => !$workflow->active]);

        return response()->json([
            'id' => $workflow->id,
            'is_active' => $workflow->active,
        ]);
    }

    /**
     * Duplicate a workflow
     */
    public function duplicate(Request $request, $id)
    {
        $user = Auth::user();

        $businessIds = BusinessProfile::where('user_id', $user->id)
            ->pluck('id')
            ->merge(
                \App\Models\TeamMember::where('user_id', $user->id)
                    ->where('is_active', true)
                    ->pluck('business_id')
            )
            ->unique();

        $original = AutomationWorkflow::whereIn('business_id', $businessIds)
            ->findOrFail($id);

        $duplicate = $original->replicate();
        $duplicate->name = $original->name . ' (Copy)';
        $duplicate->executions_count = 0;
        $duplicate->last_executed_at = null;
        $duplicate->save();

        return response()->json([
            'id' => $duplicate->id,
            'name' => $duplicate->name,
            'description' => $duplicate->description,
            'is_active' => $duplicate->active,
            'trigger' => $duplicate->trigger_config,
            'conditions' => $duplicate->conditions,
            'actions' => $duplicate->actions_config,
            'execution_count' => $duplicate->executions_count,
            'last_executed_at' => $duplicate->last_executed_at?->toISOString(),
            'created_at' => $duplicate->created_at->toISOString(),
        ], 201);
    }

    /**
     * Test a workflow against a conversation
     */
    public function test(Request $request, $id)
    {
        $request->validate([
            'conversation_id' => 'required|integer',
        ]);

        $user = Auth::user();

        $businessIds = BusinessProfile::where('user_id', $user->id)
            ->pluck('id')
            ->merge(
                \App\Models\TeamMember::where('user_id', $user->id)
                    ->where('is_active', true)
                    ->pluck('business_id')
            )
            ->unique();

        $workflow = AutomationWorkflow::whereIn('business_id', $businessIds)
            ->findOrFail($id);

        $conversation = Conversation::whereIn('business_id', $businessIds)
            ->findOrFail($request->conversation_id);

        $automationEngine = app(AutomationEngine::class);
        $results = $automationEngine->executeWorkflow($workflow, $conversation, testMode: true);

        return response()->json($results);
    }

    /**
     * Get workflow execution history
     */
    public function executions(Request $request, $id)
    {
        $user = Auth::user();

        $businessIds = BusinessProfile::where('user_id', $user->id)
            ->pluck('id')
            ->merge(
                \App\Models\TeamMember::where('user_id', $user->id)
                    ->where('is_active', true)
                    ->pluck('business_id')
            )
            ->unique();

        $workflow = AutomationWorkflow::whereIn('business_id', $businessIds)
            ->findOrFail($id);

        $executions = WorkflowExecution::where('workflow_id', $workflow->id)
            ->orderBy('created_at', 'desc')
            ->paginate(50);

        return response()->json([
            'data' => $executions->map(function ($execution) {
                return [
                    'id' => $execution->id,
                    'workflow_id' => $execution->workflow_id,
                    'status' => $execution->status,
                    'trigger_data' => $execution->trigger_data,
                    'results' => $execution->results,
                    'error_message' => $execution->error_message,
                    'test_mode' => $execution->test_mode,
                    'started_at' => $execution->started_at?->toISOString(),
                    'completed_at' => $execution->completed_at?->toISOString(),
                    'created_at' => $execution->created_at->toISOString(),
                ];
            }),
            'meta' => [
                'total' => $executions->total(),
                'per_page' => $executions->perPage(),
                'current_page' => $executions->currentPage(),
                'last_page' => $executions->lastPage(),
            ],
        ]);
    }

    /**
     * Get workflow stats
     */
    public function getStats(Request $request, $id)
    {
        $user = Auth::user();

        $businessIds = BusinessProfile::where('user_id', $user->id)
            ->pluck('id')
            ->merge(
                \App\Models\TeamMember::where('user_id', $user->id)
                    ->where('is_active', true)
                    ->pluck('business_id')
            )
            ->unique();

        $workflow = AutomationWorkflow::whereIn('business_id', $businessIds)
            ->findOrFail($id);

        $stats = [
            'total_executions' => $workflow->executions_count,
            'last_executed_at' => $workflow->last_executed_at?->toISOString(),
            'successful_executions' => WorkflowExecution::where('workflow_id', $workflow->id)
                ->where('status', 'completed')
                ->count(),
            'failed_executions' => WorkflowExecution::where('workflow_id', $workflow->id)
                ->where('status', 'failed')
                ->count(),
            'test_executions' => WorkflowExecution::where('workflow_id', $workflow->id)
                ->where('test_mode', true)
                ->count(),
        ];

        return response()->json($stats);
    }

    /**
     * Get workflow templates
     */
    public function getTemplates(Request $request)
    {
        $templates = [
            [
                'id' => 'template-welcome',
                'name' => 'Welcome New Customers',
                'description' => 'Automatically welcome new customers and add a tag',
                'trigger' => [
                    'type' => 'first_contact',
                    'conditions' => [],
                ],
                'actions' => [
                    [
                        'type' => 'send_message',
                        'message' => 'Welcome! How can we help you today?',
                    ],
                    [
                        'type' => 'add_tag',
                        'tag' => 'new_customer',
                    ],
                ],
            ],
            [
                'id' => 'template-price',
                'name' => 'Price Inquiry Handler',
                'description' => 'Detect price-related keywords and provide information',
                'trigger' => [
                    'type' => 'keyword',
                    'conditions' => [
                        'keywords' => ['price', 'cost', 'how much'],
                        'match_type' => 'any',
                    ],
                ],
                'actions' => [
                    [
                        'type' => 'add_tag',
                        'tag' => 'price_inquiry',
                    ],
                    [
                        'type' => 'send_message',
                        'message' => 'I\'d be happy to help with pricing information. What product are you interested in?',
                    ],
                ],
            ],
            [
                'id' => 'template-escalate',
                'name' => 'Escalate Complex Issues',
                'description' => 'Escalate conversations with specific keywords to human agents',
                'trigger' => [
                    'type' => 'keyword',
                    'conditions' => [
                        'keywords' => ['complaint', 'angry', 'refund'],
                        'match_type' => 'any',
                    ],
                ],
                'actions' => [
                    [
                        'type' => 'add_tag',
                        'tag' => 'needs_attention',
                    ],
                    [
                        'type' => 'escalate',
                        'reason' => 'Customer expressed dissatisfaction',
                    ],
                ],
            ],
        ];

        return response()->json($templates);
    }
}
