<?php

namespace App\Services;

use App\Helpers\AppHelper;
use App\Models\Campaign;
use App\Models\StatsAggregation;
use App\Models\StatsTrackCampaign;
use Illuminate\Validation\ValidationException;

class CampaignService
{
    public function create(?int $projectId, string $name): Campaign
    {
        return Campaign::firstOrCreate([
            'project_id' => $projectId,
            'name' => $name,
        ]);
    }

    public function rename(Campaign $campaign, string $name): Campaign
    {
        $campaign->update(['name' => $name]);

        return $campaign;
    }

    /**
     * Attach a tracked utm_campaign dimension to a campaign. The name is resolved
     * via firstOrCreate, identical to what the tracking pipeline does on the first
     * matching hit, so a dimension linked up front merges with later real traffic.
     *
     * @throws ValidationException when the dimension already belongs to another campaign
     */
    public function linkTrackCampaign(Campaign $campaign, string $name): StatsTrackCampaign
    {
        $trackCampaign = StatsTrackCampaign::firstOrCreate([
            'team_id' => AppHelper::getTeamId(),
            'name' => $name,
        ]);

        if ($trackCampaign->campaign_id !== null && $trackCampaign->campaign_id !== $campaign->id) {
            throw ValidationException::withMessages([
                'name' => "\"{$name}\" is already linked to another campaign.",
            ]);
        }

        $trackCampaign->update(['campaign_id' => $campaign->id]);

        $this->invalidateAggregations($campaign);

        return $trackCampaign;
    }

    public function unlinkTrackCampaign(Campaign $campaign, StatsTrackCampaign $trackCampaign): void
    {
        if ($trackCampaign->campaign_id !== $campaign->id) {
            return;
        }

        $trackCampaign->update(['campaign_id' => null]);

        $this->invalidateAggregations($campaign);
    }

    // Cached aggregations hash on the campaign id, not its mutable set of linked utm
    // dimensions, so changing the membership would otherwise leave them stale.
    private function invalidateAggregations(Campaign $campaign): void
    {
        StatsAggregation::where('team_id', $campaign->team_id)
            ->whereRaw("(filter::jsonb ->> 'campaign')::int = ?", [$campaign->id])
            ->delete();
    }
}
