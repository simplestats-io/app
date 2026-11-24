<?php

namespace App\Policies;

use App\Models\Campaign;
use App\Models\Project;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class CampaignPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $user->hasTeamPermission($user->currentTeam, 'action:read')
            && $user->currentTeam->tracksUsage(Project::USAGE_PAYMENTS);
    }

    public function view(User $user, Campaign $campaign): bool
    {
        return $user->hasTeamPermission($campaign->team, 'action:read')
            && $this->canReachCampaignProjects($user, $campaign)
            && ($campaign->spansAllProjects()
                ? $campaign->team->tracksUsage(Project::USAGE_PAYMENTS)
                : $campaign->project->hasUsages([Project::USAGE_PAYMENTS]));
    }

    public function create(User $user): bool
    {
        return $user->hasTeamPermission($user->currentTeam, 'action:create')
            && $user->currentTeam->tracksUsage(Project::USAGE_PAYMENTS);
    }

    public function update(User $user, Campaign $campaign): bool
    {
        return $user->hasTeamPermission($campaign->team, 'action:update')
            && $this->canReachCampaignProjects($user, $campaign);
    }

    /**
     * A campaign for all projects would show numbers of projects outside a limited
     * member's selection, so only members with access to every project reach it.
     */
    private function canReachCampaignProjects(User $user, Campaign $campaign): bool
    {
        if ($campaign->spansAllProjects()) {
            return $user->accessibleProjectIds($campaign->team_id) === null;
        }

        return $campaign->project !== null && $user->canAccessProject($campaign->project);
    }
}
