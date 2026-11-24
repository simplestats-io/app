<?php

namespace App\Mcp\Tools\Concerns;

use App\Models\Project;
use App\Models\Team;
use Illuminate\Support\Collection;

trait ManagesTeamMembers
{
    use ResolvesMcpUser;

    /**
     * @param  array<int, int>|null  $projectIds
     * @param  Collection<int, string>  $projectNames
     * @return array<int, array{project_id: int, name: string}>|string
     */
    protected function projectAccessPayload(?array $projectIds, Collection $projectNames): array|string
    {
        if ($projectIds === null) {
            return 'all';
        }

        return collect($projectIds)
            ->filter(fn (int $projectId): bool => $projectNames->has($projectId))
            ->map(fn (int $projectId): array => ['project_id' => $projectId, 'name' => $projectNames->get($projectId)])
            ->values()
            ->all();
    }

    /**
     * @return Collection<int, string>
     */
    protected function teamProjectNames(Team $team): Collection
    {
        return Project::query()->whereBelongsTo($team)->pluck('name', 'id');
    }
}
