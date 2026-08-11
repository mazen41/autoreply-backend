<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\TeamMember;
use App\Models\User;
use App\Models\BusinessProfile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class TeamController extends Controller
{
    /**
     * Get team members for a business
     */
    public function index(Request $request, $businessId)
    {
        $business = BusinessProfile::where('user_id', Auth::id())
            ->findOrFail($businessId);

        $teamMembers = TeamMember::where('business_id', $businessId)
            ->with('user')
            ->get();

        return response()->json($teamMembers);
    }

    /**
     * Invite a team member
     */
    public function invite(Request $request, $businessId)
    {
        $request->validate([
            'email' => 'required|email',
            'role' => 'required|in:agent,viewer',
        ]);

        $business = BusinessProfile::where('user_id', Auth::id())
            ->findOrFail($businessId);

        // Check if user already exists
        $user = User::where('email', $request->email)->first();

        if ($user) {
            // User exists, create team membership
            $existingMember = TeamMember::where('business_id', $businessId)
                ->where('user_id', $user->id)
                ->first();

            if ($existingMember) {
                return response()->json(['error' => 'User is already a team member'], 400);
            }

            $teamMember = TeamMember::create([
                'business_id' => $businessId,
                'user_id' => $user->id,
                'role' => $request->role,
                'is_active' => true,
                'joined_at' => now(),
            ]);

            Log::info('Team member added (existing user)', [
                'business_id' => $businessId,
                'user_id' => $user->id,
                'role' => $request->role,
            ]);

            return response()->json(['success' => true, 'team_member' => $teamMember]);
        }

        // User doesn't exist, send invitation email
        $invitationToken = Str::random(32);
        
        // In a real implementation, you would store this token and create an invitation record
        // For now, we'll simulate the invitation process
        
        try {
            Mail::raw(
                "You have been invited to join the team at {$business->business_name}.\n\n" .
                "Role: {$request->role}\n\n" .
                "Please sign up at " . config('app.url') . "/auth/register to join the team.",
                function ($message) use ($request) {
                    $message->to($request->email)
                        ->subject('Team Invitation');
                }
            );

            Log::info('Team invitation sent', [
                'business_id' => $businessId,
                'email' => $request->email,
                'role' => $request->role,
            ]);

            return response()->json(['success' => true, 'message' => 'Invitation sent']);
        } catch (\Exception $e) {
            Log::error('Failed to send team invitation', ['error' => $e->getMessage()]);
            return response()->json(['error' => 'Failed to send invitation'], 500);
        }
    }

    /**
     * Update team member role
     */
    public function updateRole(Request $request, $businessId, $memberId)
    {
        $request->validate([
            'role' => 'required|in:owner,agent,viewer',
        ]);

        $business = BusinessProfile::where('user_id', Auth::id())
            ->findOrFail($businessId);

        $teamMember = TeamMember::where('id', $memberId)
            ->where('business_id', $businessId)
            ->findOrFail($memberId);

        // Prevent removing owner role from the last owner
        if ($teamMember->role === 'owner' && $request->role !== 'owner') {
            $ownerCount = TeamMember::where('business_id', $businessId)
                ->where('role', 'owner')
                ->count();
            
            if ($ownerCount <= 1) {
                return response()->json(['error' => 'Cannot remove the last owner'], 400);
            }
        }

        $teamMember->update(['role' => $request->role]);

        return response()->json(['success' => true, 'team_member' => $teamMember]);
    }

    /**
     * Remove team member
     */
    public function remove(Request $request, $businessId, $memberId)
    {
        $business = BusinessProfile::where('user_id', Auth::id())
            ->findOrFail($businessId);

        $teamMember = TeamMember::where('id', $memberId)
            ->where('business_id', $businessId)
            ->findOrFail($memberId);

        // Prevent removing the last owner
        if ($teamMember->role === 'owner') {
            $ownerCount = TeamMember::where('business_id', $businessId)
                ->where('role', 'owner')
                ->count();
            
            if ($ownerCount <= 1) {
                return response()->json(['error' => 'Cannot remove the last owner'], 400);
            }
        }

        $teamMember->delete();

        Log::info('Team member removed', [
            'business_id' => $businessId,
            'member_id' => $memberId,
        ]);

        return response()->json(['success' => true]);
    }

    /**
     * Assign conversation to agent
     */
    public function assignConversation(Request $request, $conversationId)
    {
        $request->validate([
            'agent_id' => 'required|exists:users,id',
        ]);

        $conversation = \App\Models\Conversation::whereHas('channel', function ($q) {
                $q->where('user_id', Auth::id());
            })
            ->findOrFail($conversationId);

        // Verify the agent is a team member
        $businessId = $conversation->business_id;
        $isTeamMember = TeamMember::where('business_id', $businessId)
            ->where('user_id', $request->agent_id)
            ->exists();

        if (!$isTeamMember) {
            return response()->json(['error' => 'Agent is not a team member of this business'], 400);
        }

        $conversation->update([
            'assigned_agent_id' => $request->agent_id,
            'assigned_at' => now(),
        ]);

        Log::info('Conversation assigned', [
            'conversation_id' => $conversationId,
            'agent_id' => $request->agent_id,
        ]);

        return response()->json(['success' => true, 'conversation' => $conversation->fresh()]);
    }

    /**
     * Get agent's assigned conversations
     */
    public function myAssignments(Request $request)
    {
        $assignments = \App\Models\Conversation::where('assigned_agent_id', Auth::id())
            ->with(['channel', 'latestMessage'])
            ->orderBy('assigned_at', 'desc')
            ->paginate(50);

        return response()->json($assignments);
    }
}
