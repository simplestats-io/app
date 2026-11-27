<?php

namespace App\Http\DataProviders;

use App\DTOs\StatsFilterDto;
use App\Http\Requests\StatsFilterRequest;
use App\Models\Project;
use App\Models\Team;
use App\Services\AdSpendService;
use App\Services\RefererService;
use App\Services\StatsDetailsService;
use App\Services\SubscriptionService;
use Illuminate\Support\Arr;

class CampaignsDataProvider extends AbstractStatsDataProvider
{
    public function __construct(
        StatsFilterRequest $request,
        StatsDetailsService $statsDetailsService,
        SubscriptionService $subscriptionService,
        RefererService $refererService,
        private readonly AdSpendService $adSpendService,
    ) {
        parent::__construct($request, $statsDetailsService, $subscriptionService, $refererService);
    }

    public function toArray(): array
    {
        $user = $this->request->user();
        $effective = $this->resolveEffectiveRequest($user);

        $filters = StatsFilterDto::fromRequest($effective);

        $teamStats = $this->loadTeamStats($user, $filters);

        return array_merge($this->commonProps($user, $filters, $teamStats), [
            // The internal ids in the profitability rows are for the AI tool
            // consuming the same service; the page works with the uuids.
            'campaigns' => $this->adSpendService->getCampaignProfitability($filters)
                ->through(fn (array $row) => Arr::except($row, ['campaign_id', 'project_id'])),
            'totals' => $this->adSpendService->rangeTotals($filters, $this->projectTracksBit($teamStats, $filters, Project::USAGE_PAYMENTS)),
            'projects' => $this->projectOptions($teamStats),
            'canCreateForAllProjects' => ! $user->hasRestrictedProjectAccess($user->currentTeam),
        ]);
    }

    /**
     * @return array<int, array{uuid: string, name: string}>
     */
    private function projectOptions(Team $teamStats): array
    {
        return $teamStats->projects
            ->reject(fn ($project): bool => $project['id'] === StatsFilterDto::TRACK_ALL)
            ->map(fn ($project): array => ['uuid' => $project['uuid'], 'name' => $project['name']])
            ->values()
            ->toArray();
    }
}
