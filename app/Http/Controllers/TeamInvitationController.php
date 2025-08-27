<?php

namespace App\Http\Controllers;

use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Mail\TeamInvitationMail;

class TeamInvitationController extends Controller
{
    /**
     * Send team invitation
     */
    public function invite(Request $request, Team $team): JsonResponse
    {
        Gate::authorize('manage', $team);

        Log::info('Team invitation request received', [
            'team_id' => $team->id,
            'request_data' => $request->all(),
            'user_id' => $request->user()->id
        ]);

        $validated = $request->validate([
            'email' => 'required|email',
            'role' => 'sometimes|in:admin,member,viewer',
        ]);

        // Set default role if not provided
        $validated['role'] = $validated['role'] ?? 'member';

        Log::info('Validated invitation data', [
            'validated' => $validated,
            'team_id' => $team->id
        ]);

        // Check if user is already a team member
        $existingUser = User::where('email', $validated['email'])->first();
        if ($existingUser && $team->isMember($existingUser)) {
            return response()->json([
                'success' => false,
                'message' => 'User is already a member of this team'
            ], 400);
        }

        // Check if there's already a pending invitation
        $existingInvitation = TeamInvitation::where('team_id', $team->id)
            ->where('email', $validated['email'])
            ->pending()
            ->first();

        try {
            DB::beginTransaction();

            // If invitation exists, update it with new token and timestamp (resend)
            if ($existingInvitation) {
                $invitation = $existingInvitation;
                $invitation->update([
                    'token' => (string) \Illuminate\Support\Str::uuid(), // Use UUID like model boot method
                    'invited_by' => $request->user()->id,
                    'role' => $validated['role'],
                    'created_at' => now(), // Reset invitation timestamp
                    'expires_at' => now()->addDays(7), // Reset expiration
                ]);
                
                Log::info('Resending existing invitation', [
                    'invitation_id' => $invitation->id,
                    'email' => $validated['email']
                ]);
            } else {
                // Create new invitation
                $invitation = TeamInvitation::create([
                    'team_id' => $team->id,
                    'invited_by' => $request->user()->id,
                    'email' => $validated['email'],
                    'role' => $validated['role'],
                ]);
                
                Log::info('Creating new invitation', [
                    'invitation_id' => $invitation->id,
                    'email' => $validated['email']
                ]);
            }

            // Send invitation email
            try {
                Log::info('Attempting to send invitation email', [
                    'invitation_id' => $invitation->id,
                    'email' => $validated['email'],
                    'mail_driver' => config('mail.default'),
                    'from_address' => config('mail.from.address'),
                ]);
                
                Mail::to($validated['email'])->send(new TeamInvitationMail($invitation));
                
                Log::info('Invitation email sent successfully', [
                    'invitation_id' => $invitation->id,
                    'email' => $validated['email']
                ]);
            } catch (\Exception $e) {
                Log::error('Failed to send invitation email', [
                    'invitation_id' => $invitation->id,
                    'email' => $validated['email'],
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
                
                // Invitation created successfully even if email fails
            }

            DB::commit();

            $message = $existingInvitation ? 'Invitation resent successfully' : 'Invitation sent successfully';

            return response()->json([
                'success' => true,
                'message' => $message,
                'data' => $invitation->load(['team', 'inviter'])
            ], 201);

        } catch (\Exception $e) {
            DB::rollback();
            Log::error('Failed to create team invitation', [
                'team_id' => $team->id,
                'email' => $validated['email'] ?? 'unknown',
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to send invitation: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Accept team invitation
     */
    public function accept(Request $request, string $token): JsonResponse
    {
        $invitation = TeamInvitation::where('token', $token)->first();

        if (!$invitation) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid invitation token'
            ], 404);
        }

        if (!$invitation->isPending()) {
            $status = $invitation->isExpired() ? 'expired' : $invitation->status;
            return response()->json([
                'success' => false,
                'message' => "Invitation is {$status}"
            ], 400);
        }

        // Check if user exists and matches invitation email
        $user = $request->user();
        if ($user->email !== $invitation->email) {
            return response()->json([
                'success' => false,
                'message' => 'This invitation is not for your email address'
            ], 403);
        }

        // Check if user is already a team member
        if ($invitation->team->isMember($user)) {
            $invitation->update(['status' => 'accepted', 'accepted_at' => now()]);
            return response()->json([
                'success' => false,
                'message' => 'You are already a member of this team'
            ], 400);
        }

        try {
            DB::beginTransaction();

            // Add user to team
            $invitation->team->addMember($user, $invitation->role);

            // Mark invitation as accepted
            $invitation->accept();

            // Auto-add user to all boards associated with this team
            $this->addUserToTeamBoards($user, $invitation->team);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Successfully joined the team! You now have access to all team boards.',
                'data' => [
                    'team' => $invitation->team->load(['owner', 'members']),
                    'role' => $invitation->role,
                    'boards_count' => $invitation->team->boards()->count()
                ]
            ]);

        } catch (\Exception $e) {
            DB::rollback();
            Log::error('Failed to accept team invitation', [
                'invitation_id' => $invitation->id,
                'user_id' => $user->id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to join team'
            ], 500);
        }
    }

    /**
     * Reject team invitation
     */
    public function reject(Request $request, string $token): JsonResponse
    {
        $invitation = TeamInvitation::where('token', $token)->first();

        if (!$invitation) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid invitation token'
            ], 404);
        }

        if (!$invitation->isPending()) {
            return response()->json([
                'success' => false,
                'message' => 'Invitation is no longer pending'
            ], 400);
        }

        $invitation->reject();

        return response()->json([
            'success' => true,
            'message' => 'Invitation rejected'
        ]);
    }

    /**
     * Add user to all boards associated with the team
     */
    private function addUserToTeamBoards(User $user, Team $team): void
    {
        $teamBoards = $team->boards;

        foreach ($teamBoards as $board) {
            try {
                // The user automatically gets access to team boards through team membership
                // This is handled by the board access policies and relationships
                
                Log::info('User gained access to team board', [
                    'user_id' => $user->id,
                    'team_id' => $team->id,
                    'board_id' => $board->id,
                    'board_name' => $board->name
                ]);

            } catch (\Exception $e) {
                Log::error('Failed to log board access', [
                    'user_id' => $user->id,
                    'board_id' => $board->id,
                    'error' => $e->getMessage()
                ]);
            }
        }
    }

    /**
     * Get invitation details (for preview before accepting/rejecting)
     */
    public function show(string $token): JsonResponse
    {
        $invitation = TeamInvitation::where('token', $token)
            ->with(['team.owner', 'inviter'])
            ->first();

        if (!$invitation) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid invitation token'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $invitation->id,
                'team_name' => $invitation->team->name,
                'team_description' => $invitation->team->description,
                'role' => $invitation->role,
                'status' => $invitation->status,
                'expires_at' => $invitation->expires_at,
                'invited_by' => $invitation->inviter->name,
                'team_owner' => $invitation->team->owner->name,
                'is_expired' => $invitation->isExpired(),
                'is_pending' => $invitation->isPending(),
            ]
        ]);
    }

    /**
     * List team invitations (for team admins)
     */
    public function index(Request $request, Team $team): JsonResponse
    {
        Gate::authorize('view', $team);

        $invitations = $team->invitations()
            ->with(['inviter'])
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $invitations
        ]);
    }

    /**
     * Cancel/delete invitation
     */
    public function destroy(Team $team, TeamInvitation $invitation): JsonResponse
    {
        Gate::authorize('manage', $team);

        if ($invitation->team_id !== $team->id) {
            return response()->json([
                'success' => false,
                'message' => 'Invitation does not belong to this team'
            ], 404);
        }

        $invitation->delete();

        return response()->json([
            'success' => true,
            'message' => 'Invitation cancelled'
        ]);
    }
}
