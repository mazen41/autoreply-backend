<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\TeamMember;
use App\Models\User;
use App\Models\BusinessProfile;
use App\Models\TeamInvitation;
use App\Models\Notification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
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
        $business = app(\App\Services\BusinessAccessService::class)
            ->authorize($request->user(), $businessId, 'manage_team');

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
            'role' => 'required|in:manager,agent,viewer',
        ]);

        $business = app(\App\Services\BusinessAccessService::class)
            ->authorize($request->user(), $businessId, 'manage_team');

        $email = mb_strtolower(trim($request->email));
        if (TeamMember::where('business_id', $businessId)->whereHas('user', fn ($q) => $q->whereRaw('LOWER(email) = ?', [$email]))
            ->where('is_active', true)->exists()) {
            return response()->json(['error' => 'User is already a team member'], 409);
        }
        if (TeamInvitation::where('business_id', $businessId)->where('email', $email)->where('status', 'pending')->exists()) {
            return response()->json(['error' => 'A pending invitation already exists'], 409);
        }

        $invitation = $this->createInvitation($request, $business, $email, $request->role);
        try {
            $this->sendInvitationEmail($invitation, $business);
        } catch (\Throwable $e) {
            Log::error('Team invitation email failed', ['business_id' => $businessId, 'invitation_id' => $invitation->id, 'exception_type' => get_class($e)]);
            return response()->json(['error' => 'Invitation was saved but email delivery failed. Use resend after checking mail delivery.'], 502);
        }

        $invitee = User::whereRaw('LOWER(email) = ?', [$email])->first();
        if ($invitee) {
            Notification::create([
                'user_id' => $invitee->id,
                'type' => 'team_invitation',
                'title' => 'Team invitation',
                'message' => "You were invited to {$business->business_name} as {$invitation->role}.",
                'data' => ['business_id' => $business->id, 'invitation_id' => $invitation->id, 'role' => $invitation->role],
                'action_url' => '/team/invitations/accept',
                'is_read' => false,
            ]);
        }

        return response()->json(['success' => true, 'invitation' => $invitation], 201);
    }

    private function createInvitation(Request $request, BusinessProfile $business, string $email, string $role): TeamInvitation
    {
        $token = Str::random(64);
        $invitation = TeamInvitation::create([
            'business_id' => $business->id,
            'invited_by' => $request->user()->id,
            'email' => $email,
            'role' => $role,
            'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addDays(7),
            'status' => 'pending',
        ]);
        $invitation->setAttribute('delivery_token', $token);
        return $invitation;
    }

    private function sendInvitationEmail(TeamInvitation $invitation, BusinessProfile $business): void
    {
        $token = $invitation->getAttribute('delivery_token');
        $acceptUrl = rtrim((string) config('app.frontend_url', env('FRONTEND_URL', '')), '/')
            . '/accept-invitation#token=' . rawurlencode($token);
        Mail::raw(
            "You have been invited to join {$business->business_name} as {$invitation->role}.\n\nAccept the invitation: {$acceptUrl}\n\nThis invitation expires {$invitation->expires_at->toDayDateTimeString()}.",
            fn ($message) => $message->to($invitation->email)->subject('Team Invitation')
        );
        $invitation->setAttribute('delivery_token', null);
    }

    /** Public invitation preview. The raw token is only accepted as input and is never returned. */
    public function checkPublicInvitation(string $token)
    {
        abort_unless(preg_match('/^[A-Za-z0-9]{64}$/', $token) === 1, 404);
        $invitation = TeamInvitation::with('business:id,business_name')
            ->where('token_hash', hash('sha256', $token))
            ->first();

        if (!$invitation || $invitation->status !== 'pending') {
            return response()->json(['message' => 'Invitation is invalid or no longer pending.'], 410);
        }
        if ($invitation->expires_at->isPast()) {
            $invitation->update(['status' => 'expired']);
            return response()->json(['message' => 'Invitation has expired. Ask the owner to resend it.'], 410);
        }

        return response()->json([
            'email' => $invitation->email,
            'role' => $invitation->role,
            'business_name' => $invitation->business?->business_name,
            'expires_at' => $invitation->expires_at,
            'requires_existing_account_password' => User::whereRaw('LOWER(email) = ?', [mb_strtolower($invitation->email)])->exists(),
        ]);
    }

    /** Accept an invitation while creating a new user or authenticating an existing user. */
    public function acceptPublicInvitation(Request $request, string $token)
    {
        abort_unless(preg_match('/^[A-Za-z0-9]{64}$/', $token) === 1, 404);
        $validated = $request->validate([
            'name' => ['nullable', 'string', 'max:255'],
            'password' => ['required', 'string', 'min:8', 'max:255'],
        ]);

        $result = DB::transaction(function () use ($token, $validated) {
            $invitation = TeamInvitation::where('token_hash', hash('sha256', $token))->lockForUpdate()->first();
            if (!$invitation || $invitation->status !== 'pending' || $invitation->expires_at->isPast()) {
                if ($invitation && $invitation->status === 'pending') {
                    $invitation->update(['status' => 'expired']);
                }
                abort(410, 'Invitation is invalid or expired. Ask the owner to resend it.');
            }

            $email = mb_strtolower(trim($invitation->email));
            $user = User::whereRaw('LOWER(email) = ?', [$email])->lockForUpdate()->first();
            if ($user) {
                if (!Hash::check($validated['password'], $user->password)) {
                    abort(403, 'Enter the existing account password to accept this invitation.');
                }
            } else {
                $name = trim((string) ($validated['name'] ?? ''));
                if ($name === '') {
                    throw \Illuminate\Validation\ValidationException::withMessages([
                        'name' => ['A name is required to create your account.'],
                    ]);
                }
                $user = User::create([
                    'name' => $name,
                    'email' => $email,
                    'password' => Hash::make($validated['password']),
                    'business_id' => $invitation->business_id,
                ]);
            }

            $member = TeamMember::updateOrCreate(
                ['business_id' => $invitation->business_id, 'user_id' => $user->id],
                ['role' => $invitation->role, 'is_active' => true, 'joined_at' => now()]
            );
            $invitation->update(['status' => 'accepted', 'accepted_at' => now()]);

            return [$user, $member];
        });

        [$user, $member] = $result;
        return response()->json([
            'success' => true,
            'user' => $user,
            'team_member' => $member,
            'token' => $user->createToken('auth_token')->plainTextToken,
        ]);
    }

    /** Rejecting a delivered invitation revokes it; the raw token remains unpersisted. */
    public function rejectPublicInvitation(string $token)
    {
        abort_unless(preg_match('/^[A-Za-z0-9]{64}$/', $token) === 1, 404);
        $updated = DB::transaction(function () use ($token) {
            $invitation = TeamInvitation::where('token_hash', hash('sha256', $token))->lockForUpdate()->first();
            if (!$invitation || $invitation->status !== 'pending' || $invitation->expires_at->isPast()) {
                return false;
            }
            $invitation->update(['status' => 'revoked']);
            return true;
        });

        if (!$updated) {
            return response()->json(['message' => 'Invitation is invalid, expired, or no longer pending.'], 410);
        }
        return response()->json(['success' => true]);
    }

    public function resendInvitation(Request $request, $businessId, $invitationId)
    {
        $business = app(\App\Services\BusinessAccessService::class)->authorize($request->user(), $businessId, 'manage_team');
        $invitation = TeamInvitation::where('business_id', $business->id)->whereKey($invitationId)->where('status', 'pending')->firstOrFail();
        $invitation->update(['status' => 'expired']);
        $replacement = $this->createInvitation($request, $business, $invitation->email, $invitation->role);
        try {
            $this->sendInvitationEmail($replacement, $business);
        } catch (\Throwable $e) {
            Log::error('Team invitation resend failed', ['business_id' => $business->id, 'invitation_id' => $replacement->id, 'exception_type' => get_class($e)]);
            return response()->json(['error' => 'Invitation was refreshed but email delivery failed.'], 502);
        }
        return response()->json(['success' => true, 'invitation' => $replacement]);
    }

    public function revokeInvitation(Request $request, $businessId, $invitationId)
    {
        $business = app(\App\Services\BusinessAccessService::class)->authorize($request->user(), $businessId, 'manage_team');
        $invitation = TeamInvitation::where('business_id', $business->id)->whereKey($invitationId)->where('status', 'pending')->firstOrFail();
        $invitation->update(['status' => 'revoked']);
        return response()->json(['success' => true]);
    }

    public function pendingInvitations(Request $request, $businessId)
    {
        app(\App\Services\BusinessAccessService::class)->authorize($request->user(), $businessId, 'manage_team');
        $invitations = TeamInvitation::where('business_id', $businessId)
            ->where('status', 'pending')
            ->where('expires_at', '>', now())
            ->orderByDesc('created_at')
            ->get(['id', 'business_id', 'email', 'role', 'expires_at', 'created_at']);

        return response()->json($invitations);
    }

    public function myPendingInvitations(Request $request)
    {
        $email = mb_strtolower(trim((string) $request->user()->email));
        $invitations = TeamInvitation::with('business:id,business_name')
            ->whereRaw('LOWER(email) = ?', [$email])
            ->where('status', 'pending')
            ->where('expires_at', '>', now())
            ->orderByDesc('created_at')
            ->get(['id', 'business_id', 'email', 'role', 'expires_at', 'created_at']);

        return response()->json($invitations);
    }

    public function acceptInvitationById(Request $request, $invitationId)
    {
        $invitation = TeamInvitation::whereKey($invitationId)->firstOrFail();
        if (mb_strtolower(trim((string) $request->user()->email)) !== mb_strtolower($invitation->email)) {
            return response()->json(['error' => 'This invitation was sent to a different email address.'], 403);
        }
        if ($invitation->status !== 'pending' || $invitation->expires_at->isPast()) {
            if ($invitation->status === 'pending') {
                $invitation->update(['status' => 'expired']);
            }
            return response()->json(['error' => 'Invitation is invalid, expired, or no longer pending.'], 410);
        }

        return $this->acceptInvitationRecord($invitation, $request->user());
    }

    public function acceptInvitation(Request $request)
    {
        $validated = $request->validate(['token' => 'required|string|size:64']);
        $invitation = TeamInvitation::where('token_hash', hash('sha256', $validated['token']))->first();
        if (!$invitation || $invitation->status !== 'pending') {
            return response()->json(['error' => 'Invitation is invalid or no longer pending.'], 410);
        }
        if ($invitation->expires_at->isPast()) {
            $invitation->update(['status' => 'expired']);
            return response()->json(['error' => 'Invitation has expired. Ask the owner to resend it.'], 410);
        }
        $user = $request->user();
        if (mb_strtolower($user->email) !== $invitation->email) {
            return response()->json(['error' => 'Sign in with the email address that received the invitation.'], 403);
        }

        return $this->acceptInvitationRecord($invitation, $user);
    }

    private function acceptInvitationRecord(TeamInvitation $invitation, User $user)
    {
        $member = DB::transaction(function () use ($invitation, $user) {
            $locked = TeamInvitation::whereKey($invitation->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'pending' || $locked->expires_at->isPast()
                || mb_strtolower(trim((string) $user->email)) !== mb_strtolower($locked->email)) {
                abort(410, 'Invitation is invalid or expired.');
            }
            $member = TeamMember::updateOrCreate(
                ['business_id' => $locked->business_id, 'user_id' => $user->id],
                ['role' => $locked->role, 'is_active' => true, 'joined_at' => now()]
            );
            $locked->update(['status' => 'accepted', 'accepted_at' => now()]);
            return $member;
        });

        return response()->json(['success' => true, 'team_member' => $member]);
    }

    /**
     * Update team member role
     */
    public function updateRole(Request $request, $businessId, $memberId)
    {
        $request->validate([
            'role' => 'required|in:owner,manager,agent,viewer',
        ]);

        $business = app(\App\Services\BusinessAccessService::class)
            ->authorize($request->user(), $businessId, 'manage_team');

        $teamMember = TeamMember::where('id', $memberId)
            ->where('business_id', $businessId)
            ->findOrFail($memberId);

        $actorIsBusinessOwner = (int) $business->user_id === (int) $request->user()->id;

        // No self-escalation or self-demotion through this endpoint.
        if ((int) $teamMember->user_id === (int) $request->user()->id) {
            return response()->json(['error' => 'You cannot change your own role'], 403);
        }

        // Only the business owner can grant or hold the owner role.
        if ($request->role === 'owner' && !$actorIsBusinessOwner) {
            return response()->json(['error' => 'Only the business owner can assign the owner role'], 403);
        }

        // Last-owner protection counts the business owner plus team owners.
        if ($teamMember->role === 'owner' && $request->role !== 'owner') {
            $ownerCount = 1 + TeamMember::where('business_id', $businessId)
                ->where('role', 'owner')
                ->count();

            if ($ownerCount <= 1) {
                return response()->json(['error' => 'Cannot demote the last owner'], 400);
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
        $business = app(\App\Services\BusinessAccessService::class)
            ->authorize($request->user(), $businessId, 'manage_team');

        $teamMember = TeamMember::where('id', $memberId)
            ->where('business_id', $businessId)
            ->findOrFail($memberId);

        $removed = DB::transaction(function () use ($teamMember, $businessId) {
            $lockedMember = TeamMember::whereKey($teamMember->id)->lockForUpdate()->firstOrFail();
            // The business owner is never a team row and always remains an owner,
            // so the business owner can never be removed through this endpoint.
            $business = BusinessProfile::whereKey($businessId)->lockForUpdate()->firstOrFail();
            if ((int) $business->user_id === (int) $lockedMember->user_id) {
                return false;
            }

            $memberUser = User::whereKey($lockedMember->user_id)->lockForUpdate()->first();
            // Soft removal: keep the row for history; access checks require is_active.
            $lockedMember->update(['is_active' => false]);

            if ($memberUser && (int) $memberUser->business_id === (int) $businessId) {
                // Fall back to another active membership, then to a business the user owns, else null.
                $nextBusinessId = TeamMember::where('user_id', $memberUser->id)
                    ->where('is_active', true)
                    ->orderByDesc('joined_at')
                    ->orderBy('id')
                    ->value('business_id')
                    ?? BusinessProfile::where('user_id', $memberUser->id)->value('id');
                $memberUser->forceFill(['business_id' => $nextBusinessId])->save();
            }

            return true;
        });

        if (!$removed) {
            return response()->json(['error' => 'Cannot remove the last owner'], 400);
        }

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

        $conversation = \App\Models\Conversation::findOrFail($conversationId);

        // Business-based authorization: owner or member with conversation_write.
        $businessId = $conversation->business_id;
        app(\App\Services\BusinessAccessService::class)
            ->authorize($request->user(), $businessId, 'conversation_write');

        // Assignee must be a business owner or an active team member.
        $assigneeIsOwner = BusinessProfile::where('id', $businessId)
            ->where('user_id', $request->agent_id)
            ->exists();
        $isTeamMember = $assigneeIsOwner || TeamMember::where('business_id', $businessId)
            ->where('user_id', $request->agent_id)
            ->where('is_active', true)
            ->exists();

        if (!$isTeamMember) {
            return response()->json(['error' => 'Agent is not a team member of this business'], 400);
        }

        $conversation->update([
            'assigned_agent_id' => $request->agent_id,
            'assigned_at' => now(),
        ]);

        // Broadcast the assignment change so every agent viewing the
        // inbox sees the new assignee immediately — no refresh.
        broadcast(new \App\Events\ConversationUpdated($conversation->fresh()));

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

    /**
     * The current user's role and abilities for one business. The frontend
     * uses this list instead of its own role logic.
     */
    public function myAccess(Request $request, $businessId)
    {
        $business = app(\App\Services\BusinessAccessService::class)
            ->authorize($request->user(), $businessId, 'read');

        if ((int) $business->user_id === (int) $request->user()->id) {
            $role = 'owner';
        } else {
            $role = TeamMember::where('business_id', $business->id)
                ->where('user_id', $request->user()->id)
                ->where('is_active', true)
                ->value('role');
        }

        return response()->json([
            'business_id' => (int) $business->id,
            'role' => $role,
            'permissions' => TeamMember::permissionsFor($role),
        ]);
    }
}
