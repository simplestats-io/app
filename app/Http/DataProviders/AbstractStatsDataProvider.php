<?php

namespace App\Http\DataProviders;

use App\DTOs\StatsFilterDto;
use App\Enums\StatsRangePreset;
use App\Http\Requests\StatsFilterRequest;
use App\Models\Project;
use App\Models\Team;
use App\Models\User;
use App\Services\RefererService;
use App\Services\StatsCustomEventService;
use App\Services\StatsCustomPropertyService;
use App\Services\StatsDetailsService;
use App\Services\StatsService;
use App\Services\SubscriptionService;
use Illuminate\Support\Facades\Request;

/**
 * Shared base for the stats pages that render the StatsFilter (dashboard,
 * recurring revenue, ...): resolves the effective filter, loads the team's
 * projects, and assembles the common filter-shell props. Subclasses add only
 * their page-specific payload (e.g. period stats, MRR movements).
 */
abstract class AbstractStatsDataProvider extends AbstractDataProvider
{
    public function __construct(
        protected StatsFilterRequest $request,
        protected StatsDetailsService $statsDetailsService,
        protected SubscriptionService $subscriptionService,
        protected RefererService $refererService,
    ) {}

    /**
     * @return array<string, mixed>
     */
    protected function commonProps(User $user, StatsFilterDto $filters, Team $teamStats): array
    {
        $hiddenPresets = $user->settings['hiddenPresets'] ?? [];

        return [
            'teamStats' => $teamStats,
            'presets' => $this->buildPresets($user, $hiddenPresets),
            'allPresets' => StatsRangePreset::toOptions(),
            'hiddenPresets' => $hiddenPresets,
            'customPresets' => $user->customStatsRangePresets,
            'filters' => StatsService::formatFilters($filters),
            'statsGroups' => StatsService::getStatsTypesFormatted(['model']),
            'propertyDimensions' => StatsCustomPropertyService::getPropertyDimensions($filters),
            'hasCustomEvents' => StatsCustomEventService::hasCustomEvents($filters),
            'statsGroupNames' => StatsService::getFilterStatsTypeNames($filters),
            'topReferers' => $this->refererService->getTopReferers(),
            'rangeTypes' => $this->statsDetailsService->getRangeTypes(),
            'rangeCurrent' => now()->format($filters->rangeType->dateFormat()['php']),
            'freePlanWarning' => ['show' => StatsService::showFreePlanWarning($user)],
            'readDocsWarning' => ['show' => StatsService::showReadDocsWarning($user)],
            'subscriptionPlanAlarm' => $this->subscriptionService->getPlanAlarm($user->team),
            'usageBits' => Project::getUsages()->mapWithKeys(fn ($item, $key): array => [$key => $item['bit']]),
            'flagBits' => Project::getFlags()->mapWithKeys(fn ($item, $key): array => [$key => $item['bit']]),
            'comparisonTypes' => StatsService::getComparisonTypes(),
        ];
    }

    protected function resolveEffectiveRequest(User $user): StatsFilterRequest
    {
        // hack: as project_id may be set within the request class, we need to check $_GET
        $hasFilterRequest = ! empty(Request::query());

        $userFilterSettings = $user->settings['statsFilters'][$user->current_team_id] ?? [];

        if (! $hasFilterRequest && ! empty($userFilterSettings)) {
            return new StatsFilterRequest($userFilterSettings);
        }

        if ($this->request->has('preset') && $this->request->preset !== StatsRangePreset::CUSTOM->value) {
            // on preset (built-in or custom), we do not save the range
            $user->updateSettings('statsFilters.'.$user->current_team_id, $this->request->except([
                'range_start',
                'range_amount',
                'range_type',
            ]));
        }

        return $this->request;
    }

    protected function loadTeamStats(User $user, StatsFilterDto $filters): Team
    {
        $teamStats = $user->currentTeam()->with('projects')->get()->first();

        // do this before prepend all filters...
        $projectIds = $teamStats->projects->modelKeys();
        $teamStats->totalProjectsCount = count($projectIds);

        // Members limited to selected projects only get per-project views: their
        // "all" could not use the shared aggregation cache. Without any project
        // left they keep the "All" entry, so the frontend still has a scope.
        $perProjectOnly = $projectIds !== [] && $user->hasRestrictedProjectAccess($teamStats);
        $firstProjectId = $projectIds === [] ? StatsFilterDto::TRACK_ALL : min($projectIds);

        // we need to do prepend before keyBy('id')
        $mergedUsageBits = $teamStats->projects->reduce(fn ($carry, $project): int|string => $carry | $project['usage'], 0);
        // flags merge with AND (not OR like usage): a business-model property applies to the
        // "All Projects" view only when every project has it, so KPIs are hidden across all
        // projects only if they are redundant for all of them.
        $mergedFlags = $teamStats->projects->reduce(fn ($carry, $project): int|string => $carry & $project['flags'], Project::getFlagBitsCount());
        if (! $perProjectOnly) {
            $teamStats->projects->prepend($this->statsDetailsService->getAllFilterFor('Projects', $mergedUsageBits, $mergedFlags));
        }

        // we can't just do: $teamStats->projects = $teamStats->projects->keyBy(...);
        // this would not update $teamStats cause of laravel intern relation management...
        // Keyed by the public uuid (the "All" entry by 0), matching the uuid the
        // frontend carries in filters.project_id.
        $teamStats->setRelation('projects', $teamStats->projects->keyBy('uuid'));

        if (! $teamStats->projects->contains('id', $filters->projectId)) {
            $filters->projectId = $perProjectOnly ? $firstProjectId : StatsFilterDto::TRACK_ALL;
        }

        return $teamStats;
    }

    /**
     * @param  array<int, mixed>  $hiddenPresets
     * @return array<int, mixed>
     */
    protected function buildPresets(User $user, array $hiddenPresets): array
    {
        return collect(StatsRangePreset::toOptions())
            ->reject(fn ($p): bool => in_array($p['id'], $hiddenPresets))
            ->merge($user->customStatsRangePresets->map->toOption())
            ->sortBy('sortWeight')
            ->values()
            ->toArray();
    }

    protected function projectTracksBit(Team $teamStats, StatsFilterDto $filters, int $bit): bool
    {
        $project = $teamStats->projects->firstWhere('id', $filters->projectId);

        if ($project === null) {
            return false;
        }

        return (bool) ($project['usage'] & $bit);
    }
}
