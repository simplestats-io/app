<?php

namespace App\Http\Controllers;

use App\Http\DataProviders\CampaignsDataProvider;
use App\Http\DataProviders\CampaignShowDataProvider;
use App\Http\Requests\CampaignLinkRequest;
use App\Http\Requests\CampaignRequest;
use App\Http\Requests\CampaignUpdateRequest;
use App\Models\Campaign;
use App\Models\StatsTrackCampaign;
use App\Services\CampaignService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class CampaignController extends Controller
{
    public function __construct(
        private readonly CampaignService $campaignService,
    ) {
        $this->authorizeResource(Campaign::class, 'campaign');
    }

    public function index(CampaignsDataProvider $dataProvider): Response
    {
        return Inertia::render('Campaigns/Index', $dataProvider->toArray());
    }

    public function show(Campaign $campaign, CampaignShowDataProvider $dataProvider): Response
    {
        return Inertia::render('Campaigns/Show', $dataProvider->toArray($campaign));
    }

    public function store(CampaignRequest $request): RedirectResponse
    {
        $projectId = $request->validated('project_id');

        $campaign = $this->campaignService->create(
            $projectId === null ? null : (int) $projectId,
            $request->validated('name'),
        );

        return to_route('campaigns.show', $campaign);
    }

    public function update(CampaignUpdateRequest $request, Campaign $campaign): RedirectResponse
    {
        $this->campaignService->rename($campaign, $request->validated('name'));

        return back();
    }

    public function linkTrackCampaign(CampaignLinkRequest $request, Campaign $campaign): RedirectResponse
    {
        $this->authorize('update', $campaign);

        $this->campaignService->linkTrackCampaign($campaign, $request->validated('name'));

        return back();
    }

    public function unlinkTrackCampaign(Campaign $campaign, StatsTrackCampaign $trackCampaign): RedirectResponse
    {
        $this->authorize('update', $campaign);

        $this->campaignService->unlinkTrackCampaign($campaign, $trackCampaign);

        return back();
    }
}
