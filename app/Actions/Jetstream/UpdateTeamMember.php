<?php

namespace App\Actions\Jetstream;

use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Laravel\Jetstream\Events\TeamMemberUpdated;
use Laravel\Jetstream\Rules\Role;

/**
 * Replaces Jetstream's UpdateTeamMemberRole: the role and the project access
 * of a member are updated together.
 */
class UpdateTeamMember
{
    /**
     * @param  array<int, int>|null  $projectIds  null grants access to all projects
     */
    public function update(User $user, Team $team, User $teamMember, string $role, ?array $projectIds): void
    {
        Gate::forUser($user)->authorize('updateTeamMember', $team);

        Validator::make([
            'role' => $role,
        ], [
            'role' => [
                'required',
                'string',
                new Role,
            ],
        ])->validate();

        $team->users()->updateExistingPivot($teamMember, [
            'role' => $role,
            'project_ids' => $role === 'admin' ? null : $projectIds,
        ]);

        event(new TeamMemberUpdated($team->fresh(), $teamMember->fresh()));
    }
}
