<?php

namespace App\Http\DataProviders;

use App\Models\Membership;
use App\Models\Project;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Services\Ai\TeamAiConfig;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Laravel\Jetstream\Jetstream;

class TeamShowDataProvider extends AbstractDataProvider
{
    public function __construct(
        private readonly Request $request,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        /** @var Team $team */
        $team = $this->request->route('team');
        $user = $this->request->user();

        // Members limited to selected projects are typically clients: they must
        // not see who else is on the team, only themselves (to leave it), nor the
        // event usage of the projects of other clients.
        $canViewWholeTeam = ! $user->hasRestrictedProjectAccess($team);
        $canManageProjectAccess = Gate::check('addTeamMember', $team) || Gate::check('updateTeamMember', $team);

        $team->load([
            'owner',
            'users' => fn (BelongsToMany $relation): BelongsToMany => $canViewWholeTeam ? $relation : $relation->whereKey($user->id),
            'teamInvitations' => fn (HasMany $relation): HasMany => $canViewWholeTeam ? $relation : $relation->whereRaw('false'),
        ]);

        return [
            'team' => $team,
            'availableRoles' => array_values(Jetstream::$roles),
            'availablePermissions' => Jetstream::$permissions,
            'defaultPermissions' => Jetstream::$defaultPermissions,
            'permissions' => [
                'canAddTeamMembers' => Gate::check('addTeamMember', $team),
                'canDeleteTeam' => Gate::check('delete', $team),
                'canRemoveTeamMembers' => Gate::check('removeTeamMember', $team),
                'canUpdateTeam' => Gate::check('update', $team),
                'canUpdateTeamMembers' => Gate::check('updateTeamMember', $team),
                'canViewTeamMembers' => $canViewWholeTeam,
                'canViewEventUsage' => $canViewWholeTeam,
            ],
            ...($canManageProjectAccess ? $this->projectAccess($team) : [
                'availableProjects' => [],
                'memberProjects' => (object) [],
                'invitationProjects' => (object) [],
            ]),
            'eventsCountMonthly' => $canViewWholeTeam ? $team->events_count_monthly : null,
            'projectsEventUsage' => $canViewWholeTeam ? $team->projects()
                ->select('id', 'uuid', 'name', 'domain', 'events_count_monthly')
                ->orderByDesc('events_count_monthly')
                ->get() : [],
            'aiSettings' => [
                'provider' => $team->ai_provider,
                'model' => $team->ai_model,
                'keySet' => $team->ai_api_key !== null,
                'availableProviders' => TeamAiConfig::allowedProviders(),
            ],
        ];
    }

    /**
     * The team's projects plus each member's and invitation's project access as
     * public uuids (null = all projects), for the member management forms.
     *
     * @return array<string, mixed>
     */
    private function projectAccess(Team $team): array
    {
        // Without the global scopes: the managed team is not necessarily the
        // admin's current team.
        $projects = Project::query()
            ->withoutGlobalScopes()
            ->select([
                'id',
                'uuid',
                'name',
            ])
            ->whereBelongsTo($team)
            ->orderBy('name')
            ->get();

        $projectUuidsById = $projects->pluck('uuid', 'id');
        $userUuidsById = $team->users->pluck('uuid', 'id');

        $toUuids = fn (?array $projectIds): ?array => $projectIds === null
            ? null
            : collect($projectIds)
                ->map(fn (int $projectId): ?string => $projectUuidsById->get($projectId))
                ->filter()
                ->values()
                ->all();

        return [
            'availableProjects' => $projects->map(fn (Project $project): array => [
                'uuid' => $project->uuid,
                'name' => $project->name,
            ]),
            'memberProjects' => Membership::query()
                ->select([
                    'user_id',
                    'project_ids',
                ])
                ->where('team_id', $team->id)
                ->get()
                ->mapWithKeys(fn (Membership $membership): array => [
                    $userUuidsById->get($membership->user_id) => $toUuids($membership->project_ids),
                ]),
            'invitationProjects' => TeamInvitation::query()
                ->select([
                    'id',
                    'project_ids',
                ])
                ->whereBelongsTo($team)
                ->get()
                ->mapWithKeys(fn (TeamInvitation $invitation): array => [
                    $invitation->id => $toUuids($invitation->project_ids),
                ]),
        ];
    }
}
