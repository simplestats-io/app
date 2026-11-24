<?php

namespace App\Services;

use App\DTOs\StatsFilterDto;
use App\Enums\StatsRangeType;
use App\Helpers\AppHelper;
use App\Models\AdSpend;
use App\Models\Campaign;
use App\Models\Project;
use App\Models\StatsTrackCampaign;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class AdSpendService
{
    /**
     * Trailing window over which the subscription economics (ARPS, churn -> LTV)
     * behind LTV:CAC and CAC-payback are measured, independent of the UI range.
     * Kept short enough that the window stays close to actual recurring revenue:
     * the churn rate divides by the window's starting MRR, so a window reaching
     * back before a project's first subscriptions would yield a null lifetime.
     */
    private const int ECONOMICS_TRAILING_MONTHS = 3;

    public function __construct(
        private readonly StatsDetailsService $statsDetailsService,
    ) {}

    /**
     * Per-campaign profitability for the selected range, attributed by acquisition
     * date like the ad platforms do: the range selects the spend and the customers
     * the campaign acquired in it, and revenue is everything those customers paid
     * to date. Comparing range spend with range payments instead would pit one
     * month's cost against revenue of customers acquired months earlier.
     *
     * Each user-managed campaign bundles one or more tracked utm_campaign
     * dimensions. All campaigns are listed (even at zero), so a freshly created one
     * is visible. Paginated so the per-row LTV computation only runs for the
     * visible page.
     *
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function getCampaignProfitability(StatsFilterDto $filters, int $perPage = 20): LengthAwarePaginator
    {
        [$windowStart, $windowEnd] = $this->resolveWindow($filters);

        $campaigns = $this->campaignsForFilter($filters)->paginate($perPage);

        $revenueByUtm = $this->cohortRevenueByUtm(
            $campaigns->getCollection()->flatMap(fn (Campaign $campaign) => $campaign->statsTrackCampaigns->pluck('id'))->all(),
            $windowStart,
            $windowEnd,
        );

        return $campaigns->through(fn (Campaign $campaign): array => $this->buildRow($campaign, $revenueByUtm, $windowStart, $windowEnd, $filters));
    }

    /**
     * @return array<string, mixed>
     */
    public function campaignSummary(StatsFilterDto $filters, Campaign $campaign): array
    {
        [$windowStart, $windowEnd] = $this->resolveWindow($filters);

        $campaign->loadMissing(['statsTrackCampaigns:id,uuid,campaign_id,name', 'adSpends', 'project:id,uuid,name,usage']);

        $revenueByUtm = $this->cohortRevenueByUtm($campaign->statsTrackCampaigns->pluck('id')->all(), $windowStart, $windowEnd);

        return $this->buildRow($campaign, $revenueByUtm, $windowStart, $windowEnd, $filters);
    }

    /**
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function paginatedForCampaign(Campaign $campaign, int $perPage = 10): LengthAwarePaginator
    {
        return AdSpend::query()
            ->where('campaign_id', $campaign->id)
            ->latest('starts_on')
            ->paginate($perPage)
            ->through(fn (AdSpend $spend): array => [
                'uuid' => $spend->uuid,
                'amount' => $spend->amount,
                'currency' => $spend->currency,
                'starts_on' => $spend->starts_on->toDateString(),
                'ends_on' => $spend->ends_on->toDateString(),
            ]);
    }

    /**
     * The numeric id stays exposed here on purpose: it feeds the numeric
     * track_campaign dimension filter link on the campaign page.
     *
     * @return array<int, array{id: int, uuid: string, name: string}>
     */
    public function linkedTrackCampaigns(Campaign $campaign): array
    {
        return StatsTrackCampaign::query()
            ->where('campaign_id', $campaign->id)
            ->orderBy('name')
            ->get(['id', 'uuid', 'name'])
            ->map(fn (StatsTrackCampaign $utm): array => ['id' => $utm->id, 'uuid' => $utm->uuid, 'name' => $utm->name])
            ->all();
    }

    /**
     * @return array<int, array{uuid: string, name: string}>
     */
    public function linkableTrackCampaigns(): array
    {
        return StatsTrackCampaign::query()
            ->whereNull('campaign_id')
            ->orderBy('name')
            ->get(['uuid', 'name'])
            ->toArray();
    }

    /**
     * Campaigns for all projects only appear in the all-projects view: their spend
     * cannot be split per project, so a single project would show the whole spend
     * against a fraction of the revenue. Members limited to selected projects never
     * see them (HasProjectAccessScope drops NULL project ids).
     *
     * @return Builder<Campaign>
     */
    private function campaignsForFilter(StatsFilterDto $filters): Builder
    {
        return Campaign::query()
            ->with(['statsTrackCampaigns:id,uuid,campaign_id,name', 'adSpends', 'project:id,uuid,name,usage'])
            ->when(
                $filters->projectId !== StatsFilterDto::TRACK_ALL,
                fn ($query) => $query->where('project_id', $filters->projectId),
            )->latest();
    }

    /**
     * Acquisition-cohort revenue per tracked utm_campaign dimension: users who
     * registered in the window plus visitors who arrived in it, each with all
     * their payments to date. Guest payments only count on the visitor leg
     * (stats_user_id IS NULL), because a payment of a registered user is
     * attributed to the user's registration tracking codes.
     *
     * utm_campaign dimensions are team-wide while a campaign usually belongs to one
     * project, so the totals are split per project: a project campaign only counts
     * its own, a campaign for all projects sums them (see buildRow()). Projects
     * outside a limited member's selection are never part of the totals.
     *
     * @param  array<int, int>  $utmIds
     * @return Collection<int, Collection<int, array{net: int, reg: int, visitors: int}>> utm id => project id => totals
     */
    private function cohortRevenueByUtm(array $utmIds, CarbonImmutable $windowStart, CarbonImmutable $windowEnd): Collection
    {
        if ($utmIds === []) {
            return collect();
        }

        $window = [$windowStart->format('Y-m-d H:i:s'), $windowEnd->format('Y-m-d H:i:s')];
        $teamId = AppHelper::getTeamId();
        $accessibleProjectIds = AppHelper::accessibleProjectIds();

        $users = DB::table('stats_users as su')
            ->leftJoin('stats_payments as p', fn (JoinClause $join) => $join
                ->on('p.stats_user_id', '=', 'su.id')
                ->on('p.project_id', '=', 'su.project_id'))
            ->where('su.team_id', $teamId)
            ->when($accessibleProjectIds !== null, fn (QueryBuilder $query): QueryBuilder => $query->whereIn('su.project_id', $accessibleProjectIds))
            ->where('su.registered', true)
            ->whereIn('su.stats_track_campaign_id', $utmIds)
            ->whereBetween('su.time', $window)
            ->groupBy('su.stats_track_campaign_id', 'su.project_id')
            ->select('su.stats_track_campaign_id as utm_id', 'su.project_id')
            ->selectRaw('COUNT(DISTINCT su.id) as reg')
            ->selectRaw('COALESCE(SUM(p.net), 0) as net')
            ->get();

        $visitors = DB::table('stats_visitors as sv')
            ->leftJoin('stats_payments as p', fn (JoinClause $join) => $join
                ->on('p.stats_visitor_id', '=', 'sv.id')
                ->on('p.project_id', '=', 'sv.project_id')
                ->whereNull('p.stats_user_id'))
            ->where('sv.team_id', $teamId)
            ->when($accessibleProjectIds !== null, fn (QueryBuilder $query): QueryBuilder => $query->whereIn('sv.project_id', $accessibleProjectIds))
            ->whereIn('sv.stats_track_campaign_id', $utmIds)
            ->whereBetween('sv.time', $window)
            ->groupBy('sv.stats_track_campaign_id', 'sv.project_id')
            ->select('sv.stats_track_campaign_id as utm_id', 'sv.project_id')
            ->selectRaw('COUNT(DISTINCT sv.id) as visitors')
            ->selectRaw('COALESCE(SUM(p.net), 0) as net')
            ->get();

        $totals = [];

        foreach ($users as $row) {
            $totals[(int) $row->utm_id][(int) $row->project_id] = ['net' => (float) $row->net, 'reg' => (int) $row->reg, 'visitors' => 0];
        }

        foreach ($visitors as $row) {
            $utmId = (int) $row->utm_id;
            $projectId = (int) $row->project_id;
            $entry = $totals[$utmId][$projectId] ?? ['net' => 0.0, 'reg' => 0, 'visitors' => 0];

            $totals[$utmId][$projectId] = [...$entry, 'net' => $entry['net'] + $row->net, 'visitors' => (int) $row->visitors];
        }

        return collect($totals)->map(fn (array $byProject): Collection => collect($byProject)
            ->map(fn (array $total): array => [...$total, 'net' => (int) round($total['net'])]));
    }

    /**
     * @param  Collection<int, Collection<int, array{net: int, reg: int, visitors: int}>>  $revenueByUtm
     * @return array<string, mixed>
     */
    private function buildRow(Campaign $campaign, Collection $revenueByUtm, CarbonImmutable $windowStart, CarbonImmutable $windowEnd, StatsFilterDto $filters): array
    {
        $net = 0;
        $reg = 0;
        $visitors = 0;

        foreach ($campaign->statsTrackCampaigns as $utm) {
            $byProject = $revenueByUtm->get($utm->id, collect());
            $revenues = $campaign->spansAllProjects() ? $byProject : $byProject->only([$campaign->project_id]);

            foreach ($revenues as $revenue) {
                $net += $revenue['net'];
                $reg += $revenue['reg'];
                $visitors += $revenue['visitors'];
            }
        }

        $spend = (int) $campaign->adSpends->sum(fn (AdSpend $adSpend): int => $adSpend->allocatedFor($windowStart, $windowEnd));
        // Without spend in the range there is no acquisition cost to divide, not a free customer.
        $cac = $spend > 0 && $reg > 0 ? (int) round($spend / $reg) : null;

        return [
            'campaign_id' => $campaign->id,
            'campaign_uuid' => $campaign->uuid,
            'name' => $campaign->name,
            'project_id' => $campaign->project_id,
            'project_uuid' => $campaign->project?->uuid,
            'project_name' => $campaign->spansAllProjects() ? 'All projects' : ($campaign->project?->name ?? '–'),
            'visitors' => $visitors,
            'reg' => $reg,
            'net' => $net,
            'spend' => $spend,
            'net_profit' => $net - $spend,
            'runs_from' => $campaign->adSpends->min(fn (AdSpend $adSpend): string => $adSpend->starts_on->toDateString()),
            'runs_until' => $campaign->adSpends->max(fn (AdSpend $adSpend): string => $adSpend->ends_on->toDateString()),
            // Lets the UI tell "nothing happened in this range" apart from a genuine zero result.
            'has_activity' => $spend > 0 || $reg > 0 || $net !== 0,
            'roas' => $spend > 0 ? round($net / $spend, 2) : null,
            'cac' => $cac,
            ...$this->subscriptionKpisFor($filters, $campaign, $cac),
        ];
    }

    /**
     * LTV:CAC and CAC-payback for one campaign. Both need the campaign's subscription
     * economics and the CAC; either is null when the campaign's project does not track
     * subscriptions, has no CAC (no registrations), or has no subscribers. A churn-free
     * window yields a null LTV (no lifetime to estimate) but a valid payback (ARPS still
     * known).
     *
     * Only CAC is range-scoped (it is an acquisition cost). The economics half is
     * deliberately measured over a fixed trailing window, not the UI range: LTV is a
     * forward lifetime projection and ARPS a current snapshot, so binding them to the
     * selected range would make both KPIs drift with range length even when the
     * underlying unit economics are unchanged.
     *
     * @return array{ltv_cac: float|null, cac_payback: float|null}
     */
    private function subscriptionKpisFor(StatsFilterDto $base, Campaign $campaign, ?int $cac): array
    {
        $empty = ['ltv_cac' => null, 'cac_payback' => null];

        if ($cac === null) {
            return $empty;
        }

        $tracksSubscriptions = $campaign->spansAllProjects()
            ? $campaign->team()->sole()->tracksUsage(Project::USAGE_SUBSCRIPTION)
            : $campaign->project?->hasUsages([Project::USAGE_SUBSCRIPTION]);

        if (! $tracksSubscriptions) {
            return $empty;
        }

        $economics = $this->statsDetailsService->getSubscriptionEconomics($this->economicsFilter($base, $campaign));

        if ($economics === null) {
            return $empty;
        }

        return [
            'ltv_cac' => $economics['ltv'] !== null && $cac > 0 ? round($economics['ltv'] / $cac, 2) : null,
            'cac_payback' => $economics['arps'] > 0 ? round($cac / $economics['arps'], 1) : null,
        ];
    }

    private function economicsFilter(StatsFilterDto $base, Campaign $campaign): StatsFilterDto
    {
        $scoped = clone $base;
        $scoped->campaign = $campaign->id;
        $scoped->projectId = $campaign->project_id ?? StatsFilterDto::TRACK_ALL;
        $scoped->rangeType = StatsRangeType::MONTH;
        $scoped->rangeAmount = self::ECONOMICS_TRAILING_MONTHS;
        $scoped->rangeStart = now();

        return $scoped;
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function resolveWindow(StatsFilterDto $filters): array
    {
        $conditions = StatsRangeType::conditions($filters->rangeStart, $filters->rangeAmount)[$filters->rangeType->value];

        return [
            CarbonImmutable::parse($conditions['startDateStart']),
            CarbonImmutable::parse($conditions['endDateEnd']),
        ];
    }
}
