<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\TeamInvitation;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rules;

class RegisteredUserController extends Controller
{
    /**
     * Handle an incoming registration request.
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function store(Request $request)
    {
        $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:'.User::class],
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
        ]);

        try {
            DB::beginTransaction();

            $user = User::create([
                'name' => $request->name,
                'email' => $request->email,
                'password' => Hash::make($request->string('password')),
            ]);

            event(new Registered($user));

            Auth::login($user);

            // Auto-accept any pending team invitations for this email
            $this->autoAcceptPendingInvitations($user);

            DB::commit();

            // Return user data for frontend
            return response()->json([
                'success' => true,
                'data' => [
                    'user' => $user,
                    'message' => 'Registration successful! You have been automatically added to any teams you were invited to.'
                ]
            ]);

        } catch (\Exception $e) {
            DB::rollback();
            
            Log::error('Registration failed', [
                'email' => $request->email,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Registration failed. Please try again.'
            ], 500);
        }
    }

    /**
     * Auto-accept pending team invitations for the newly registered user
     */
    private function autoAcceptPendingInvitations(User $user): void
    {
        $pendingInvitations = TeamInvitation::where('email', $user->email)
            ->pending()
            ->get();

        foreach ($pendingInvitations as $invitation) {
            try {
                // Add user to team
                $invitation->team->addMember($user, $invitation->role);

                // Mark invitation as accepted
                $invitation->accept();

                // Auto-add user to all boards associated with this team
                $this->addUserToTeamBoards($user, $invitation->team);

                Log::info('Auto-accepted team invitation', [
                    'user_id' => $user->id,
                    'team_id' => $invitation->team->id,
                    'role' => $invitation->role
                ]);

            } catch (\Exception $e) {
                Log::error('Failed to auto-accept invitation', [
                    'user_id' => $user->id,
                    'invitation_id' => $invitation->id,
                    'error' => $e->getMessage()
                ]);
            }
        }
    }

    /**
     * Add user to all boards associated with the team
     */
    private function addUserToTeamBoards(User $user, $team): void
    {
        $teamBoards = $team->boards;

        foreach ($teamBoards as $board) {
            try {
                // Check if user is not already added to this board
                if (!$board->teams()->where('team_id', $team->id)->exists()) {
                    continue;
                }

                // The user gets access to the board through their team membership
                Log::info('User has access to team board', [
                    'user_id' => $user->id,
                    'team_id' => $team->id,
                    'board_id' => $board->id
                ]);

            } catch (\Exception $e) {
                Log::error('Failed to add user to team board', [
                    'user_id' => $user->id,
                    'board_id' => $board->id,
                    'error' => $e->getMessage()
                ]);
            }
        }
    }

    /**
     * Update the user's profile.
     */
    public function updateProfile(Request $request)
    {
        $user = $request->user();

        $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:users,email,' . $user->id],
            'bio' => ['nullable', 'string', 'max:1000'],
            'phone' => ['nullable', 'string', 'max:20'],
            'location' => ['nullable', 'string', 'max:255'],
            'website' => ['nullable', 'url', 'max:255'],
            'current_password' => ['nullable', 'string'],
            'password' => ['nullable', 'confirmed', Rules\Password::defaults()],
        ]);

        // If user is trying to change password, verify current password
        if ($request->filled('password')) {
            if (!$request->filled('current_password') || !Hash::check($request->current_password, $user->password)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Current password is incorrect'
                ], 400);
            }
        }

        // Update user data
        $user->update([
            'name' => $request->name,
            'email' => $request->email,
            'bio' => $request->bio,
            'phone' => $request->phone,
            'location' => $request->location,
            'website' => $request->website,
            'password' => $request->filled('password') ? Hash::make($request->password) : $user->password,
        ]);

        return response()->json([
            'success' => true,
            'data' => [
                'user' => $user->fresh()
            ],
            'message' => 'Profile updated successfully'
        ]);
    }
}
