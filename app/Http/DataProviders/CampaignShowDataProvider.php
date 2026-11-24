<?php

namespace App\Http\DataProviders;

use App\DTOs\StatsFilterDto;
use App\Http\Requests\StatsFilterRequest;
use App\Models\Campaign;
use App\Services\AdSpendService;
use App\Services\RefererService;
use App\Services\StatsDetailsService;
use App\Services\SubscriptionService;

class CampaignShowDataProvider extends AbstractStatsDataProvider
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

    public function toArray(?Campaign $campaign = null): array
    {
        $user = $this->request->user();
        $effective = $this->resolveEffectiveRequest($user);

        $filters = StatsFilterDto::fromRequest($effective);

        $teamStats = $this->loadTeamStats($user, $filters);

        return array_merge($this->commonProps($user, $filters, $teamStats), [
            'campaign' => ['uuid' => $campaign->uuid, 'name' => $campaign->name],
            'summary' => $this->adSpendService->campaignSummary($filters, $campaign),
            'traffic' => $this->adSpendService->campaignTraffic($filters, $campaign),
            'adSpends' => $this->adSpendService->paginatedForCampaign($campaign),
            'linkedTrackCampaigns' => $this->adSpendService->linkedTrackCampaigns($campaign),
            'linkableTrackCampaigns' => $this->adSpendService->linkableTrackCampaigns(),
        ]);
    }
}
