<?php

namespace App\Policies;

use App\Models\Project;
use App\Models\User;
use App\Services\SubscriptionService;
use Illuminate\Auth\Access\HandlesAuthorization;

class ProjectPolicy
{
    use HandlesAuthorization;

    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Project $project): bool
    {
        return $user->hasTeamPermission($project->team, 'action:read')
            && $user->canAccessProject($project);
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        $allowedProjects = $user->currentTeam->owner->sparkPlan()->options[Project::class];

        // Members limited to selected projects could not see a project they create.
        return $user->hasTeamPermission($user->currentTeam, 'action:create')
            && ! $user->hasRestrictedProjectAccess($user->currentTeam)
            && (Project::count() < $allowedProjects || $allowedProjects === SubscriptionService::UNLIMITED_AMOUNT);
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Project $project): bool
    {
        return $user->hasTeamPermission($project->team, 'action:update')
            && $user->canAccessProject($project);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Project $project): bool
    {
        return $user->hasTeamPermission($project->team, 'action:delete')
            && $user->canAccessProject($project);
    }

    public function regenerateProjectToken(User $user, Project $project): bool
    {
        return $user->hasTeamPermission($project->team, 'action:delete')
            && $user->canAccessProject($project);
    }

    public function viewProjectToken(User $user, Project $project): bool
    {
        return $user->hasTeamPermission($project->team, 'action:readSensitives')
            && $user->canAccessProject($project);
    }
}
