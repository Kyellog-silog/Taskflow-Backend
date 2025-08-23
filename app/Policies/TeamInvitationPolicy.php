<?php

namespace App\Policies;

use App\Models\TeamInvitation;
use App\Models\User;
use App\Models\Team;

class TeamInvitationPolicy
{
    /**
     * Determine whether the user can view any invitations.
     */
    public function viewAny(User $user, Team $team): bool
    {
        return $team->isAdmin($user);
    }

    /**
     * Determine whether the user can view the invitation.
     */
    public function view(?User $user, TeamInvitation $invitation): bool
    {
        // Anyone can view invitation details with valid token
        return true;
    }

    /**
     * Determine whether the user can create invitations.
     */
    public function create(User $user, Team $team): bool
    {
        return $team->isAdmin($user);
    }

    /**
     * Determine whether the user can accept the invitation.
     */
    public function accept(User $user, TeamInvitation $invitation): bool
    {
        return $user->email === $invitation->email && $invitation->isPending();
    }

    /**
     * Determine whether the user can reject the invitation.
     */
    public function reject(?User $user, TeamInvitation $invitation): bool
    {
        // Can reject without auth (for email recipients who don't have accounts)
        return $invitation->isPending();
    }

    /**
     * Determine whether the user can delete/cancel the invitation.
     */
    public function delete(User $user, TeamInvitation $invitation): bool
    {
        return $invitation->team->isAdmin($user);
    }
}
