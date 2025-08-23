<?php

namespace App\Policies;

use App\Models\Board;
use App\Models\User;

class BoardPolicy
{
    public function view(User $user, Board $board): bool
    {
        return $board->canUserAccess($user);
    }

    public function create(User $user): bool
    {
        return true; // Any authenticated user can create a board
    }

    public function update(User $user, Board $board): bool
    {
        return $board->canUserManage($user);
    }

    public function delete(User $user, Board $board): bool
    {
        return $board->canUserManage($user);
    }

    /**
     * Determine if the user can create tasks on the board.
     */
    public function createTasks(User $user, Board $board): bool
    {
        return $board->canUserCreateTasks($user);
    }

    /**
     * Determine if the user can edit tasks on the board.
     */
    public function editTasks(User $user, Board $board): bool
    {
        return $board->canUserEditTasks($user);
    }

    /**
     * Determine if the user can manage board settings.
     */
    public function manage(User $user, Board $board): bool
    {
        return $board->canUserManage($user);
    }
}
