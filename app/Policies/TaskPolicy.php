<?php

namespace App\Policies;

use App\Models\Task;
use App\Models\User;

class TaskPolicy
{
    public function view(User $user, Task $task): bool
    {
        return $task->board->canUserAccess($user);
    }

    public function create(User $user): bool
    {
        return true; // Any authenticated user can create a task (if they have board access)
    }

    public function update(User $user, Task $task): bool
    {
        // Viewers cannot edit tasks
        return $task->board->canUserEditTasks($user);
    }

    public function delete(User $user, Task $task): bool
    {
        // Viewers cannot delete tasks, only board managers or task creators
        return $task->board->canUserManage($user) || 
               ($task->created_by === $user->id && $task->board->canUserEditTasks($user));
    }

    public function move(User $user, Task $task): bool
    {
        // Viewers cannot move tasks
        return $task->board->canUserEditTasks($user);
    }
}
