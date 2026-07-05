<?php

namespace App\Policies;

use App\Models\Project;
use App\Models\User;

class ProjectPolicy
{
    public function view(User $user, Project $project): bool
    {
        return $project->isMember($user);
    }

    public function create(User $user): bool
    {
        return true; // Team permission is validated against team_id in the FormRequest
    }

    public function update(User $user, Project $project): bool
    {
        return $project->canManage($user);
    }

    public function delete(User $user, Project $project): bool
    {
        return $project->canManage($user);
    }

    public function manageLabels(User $user, Project $project): bool
    {
        return $project->canEdit($user);
    }
}
