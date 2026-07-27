<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AutomationWorkflow;
use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use App\Services\AutomationEngine;

class AutomationController extends Controller
{
    /**
     * Get all automation workflows
     */
    public function index(Request $request)
    {
        $user = Auth::user();
        
        $workflows = AutomationWorkflow::where('user_id', $user->id)
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'workflows' => $workflows,
            'total' => $workflows->count(),
            'active' => $workflows->where('active', true)->count(),
        ]);
    }

    /**
     * Create a new automation workflow
     */
    public function store(Request $request)
    {
        $user = Auth::user();
        
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'active' => 'boolean',
            'trigger_config' => 'required|array',
            'trigger_config.type' => 'required|in:keyword,time,first_contact,tag_added,message_received',
            'trigger_config.conditions' => 'required|array',
            'actions_config' => 'required|array',
        ]);

        $workflow = AutomationWorkflow::create([
            'user_id' => $user->id,
            'name' => $validated['name'],
            'active' => $validated['active'] ?? true,
            'trigger_config' => $validated['trigger_config'],
            'actions_config' => $validated['actions_config'],
        ]);

        Log::info('Automation workflow created', [
            'workflow_id' => $workflow->id,
            'user_id' => $user->id,
            'trigger_type' => $validated['trigger_config']['type']
        ]);

        return response()->json([
            'message' => 'Workflow created successfully',
            'workflow' => $workflow
        ], 201);
    }

    /**
     * Update an automation workflow
     */
    public function update(Request $request, $id)
    {
        $user = Auth::user();
        $workflow = AutomationWorkflow::where('user_id', $user->id)->find($id);

        if (!$workflow) {
            return response()->json(['error' => 'Workflow not found'], 404);
        }

        $validated = $request->validate([
            'name' => 'string|max:255',
            'active' => 'boolean',
            'trigger_config' => 'array',
            'actions_config' => 'array',
        ]);

        $workflow->update($validated);

        return response()->json([
            'message' => 'Workflow updated successfully',
            'workflow' => $workflow->fresh()
        ]);
    }

    /**
     * Delete an automation workflow
     */
    public function destroy(Request $request, $id)
    {
        $user = Auth::user();
        $workflow = AutomationWorkflow::where('user_id', $user->id)->find($id);

        if (!$workflow) {
            return response()->json(['error' => 'Workflow not found'], 404);
        }

        $workflow->delete();

        return response()->json(['message' => 'Workflow deleted']);
    }

    /**
     * Test a workflow on a specific conversation
     */
    public function test(Request $request, $id)
    {
        $user = Auth::user();
        $workflow = AutomationWorkflow::where('user_id', $user->id)->find($id);

        if (!$workflow) {
            return response()->json(['error' => 'Workflow not found'], 404);
        }

        $validated = $request->validate([
            'conversation_id' => 'required|exists:conversations,id',
        ]);

        $conversation = Conversation::with('channel')->find($validated['conversation_id']);
        
        // Verify ownership
        if ($conversation->channel->user_id !== $user->id) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        // Run workflow in test mode
        $engine = new AutomationEngine();
        $result = $engine->executeWorkflow($workflow, $conversation, true);

        return response()->json([
            'message' => 'Workflow test completed',
            'result' => $result
        ]);
    }

    /**
     * Get workflow execution statistics
     */
    public function getStats(Request $request, $id)
    {
        $user = Auth::user();
        $workflow = AutomationWorkflow::where('user_id', $user->id)->find($id);

        if (!$workflow) {
            return response()->json(['error' => 'Workflow not found'], 404);
        }

        return response()->json([
            'workflow_id' => $workflow->id,
            'executions_count' => $workflow->executions_count,
            'active' => $workflow->active,
            'created_at' => $workflow->created_at,
        ]);
    }

    /**
     * Get workflow templates
     */
    public function getTemplates(Request $request)
    {
        $templates = [
            [
                'id' => 'welcome_message',
                'name' => 'Welcome Message',
                'description' => 'Send a welcome message when a new conversation starts',
                'trigger_config' => [
                    'type' => 'first_contact',
                    'conditions' => []
                ],
                'actions_config' => [
                    [
                        'type' => 'send_message',
                        'message' => 'Welcome! How can we help you today?'
                    ]
                ]
            ],
            [
                'id' => 'keyword_response',
                'name' => 'Keyword Auto-Response',
                'description' => 'Automatically respond to specific keywords',
                'trigger_config' => [
                    'type' => 'keyword',
                    'conditions' => [
                        'keywords' => ['price', 'cost', 'pricing'],
                        'match_type' => 'any'
                    ]
                ],
                'actions_config' => [
                    [
                        'type' => 'send_message',
                        'message' => 'Our pricing starts at $X. Would you like more details?'
                    ]
                ]
            ],
            [
                'id' => 'business_hours',
                'name' => 'Business Hours Auto-Reply',
                'description' => 'Send automated response outside business hours',
                'trigger_config' => [
                    'type' => 'message_received',
                    'conditions' => [
                        'time_condition' => 'outside_hours'
                    ]
                ],
                'actions_config' => [
                    [
                        'type' => 'send_message',
                        'message' => 'We are currently closed. We will respond during business hours.'
                    ]
                ]
            ],
            [
                'id' => 'tag_customer',
                'name' => 'Auto-Tag Customers',
                'description' => 'Automatically tag conversations based on keywords',
                'trigger_config' => [
                    'type' => 'keyword',
                    'conditions' => [
                        'keywords' => ['complaint', 'issue'],
                        'match_type' => 'any'
                    ]
                ],
                'actions_config' => [
                    [
                        'type' => 'add_tag',
                        'tag' => 'complaint'
                    ]
                ]
            ]
        ];

        return response()->json(['templates' => $templates]);
    }
}