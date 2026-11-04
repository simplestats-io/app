<?php

namespace App\Http\DataProviders;

use App\Concerns\BuildsMrrProps;
use App\DTOs\StatsFilterDto;
use App\Enums\StatsRangeType;
use App\Http\Requests\StatsFilterRequest;
use App\Models\Campaign;
use App\Models\PersonalAccessToken;
use App\Models\Project;
use App\Models\Team;
use App\Models\User;
use App\Services\AdSpendService;
use App\Services\RefererService;
use App\Services\StatsDetailsService;
use App\Services\StatsService;
use App\Services\SubscriptionService;
use Illuminate\Support\Facades\Gate;

class StatsIndexDataProvider extends AbstractStatsDataProvider
{
    use BuildsMrrProps;

    private const array CHURN_KPIS = ['gross_churn', 'net_churn', 'quick_ratio'];

    public function __construct(
        StatsFilterRequest $request,
        StatsDetailsService $statsDetailsService,
        SubscriptionService $subscriptionService,
        RefererService $refererService,
        private AdSpendService $adSpendService,
    ) {
        parent::__construct($request, $statsDetailsService, $subscriptionService, $refererService);
    }

    public function toArray(): array
    {
        $user = $this->request->user();
        $effective = $this->resolveEffectiveRequest($user);

        $filters = StatsFilterDto::fromRequest($effective);

        $teamStats = $this->loadTeamStats($user, $filters);
        $teamStats = $this->buildStats($teamStats, $filters);

        $showRetention = $this->request->routeIs('retention');

        return array_merge($this->commonProps($user, $filters, $teamStats), [
            'mrr_series' => $this->buildMrrTimeSeries($teamStats, $filters),
            'mrr_series_prev' => $this->buildMrrTimeSeriesComparison($teamStats, $filters),
            'campaign_summary' => $this->campaignSummary($filters),
            'engagement_series' => $this->engagementSeriesProp($showRetention, $teamStats, $filters),
            'time_to_convert_series' => $this->timeToConvertSeriesProp($teamStats, $filters),
            'revenue_churn' => $this->revenueChurnProp($teamStats, $filters),
            'showRetention' => $showRetention,
            'retention_cohorts' => $showRetention ? $this->buildRetentionCohorts($teamStats, $filters) : null,
            'plan_breakdown' => $this->planBreakdown($teamStats, $filters),
            'awaitingFirstData' => $this->awaitingFirstData($user),
        ]);
    }

    /**
     * Until the team has received its first event the dashboard shows the installation
     * steps instead of empty charts. The token is only included for members allowed to
     * read it, everybody else is pointed to an administrator.
     *
     * @return array{project: array{uuid: string, name: string}, token: string|null}|null
     */
    private function awaitingFirstData(User $user): ?array
    {
        /** @var Team $team */
        $team = $user->currentTeam;

        if ($team->hasReceivedData()) {
            return null;
        }

        $project = $team->projects()->oldest()->first();

        if (! $project) {
            return null;
        }

        /** @var PersonalAccessToken|null $token */
        $token = Gate::forUser($user)->check('viewProjectToken', $project)
            ? $project->tokens()->first()
            : null;

        return [
            'project' => ['uuid' => $project->uuid, 'name' => $project->name],
            'token' => $token?->token_plain_part,
        ];
    }

    /**
     * Engagement (Stickiness / User Churn) powers the retention page and the metric detail
     * view. It is loaded on every metric page (not just engagement KPIs) so the detail
     * sidebar always offers these KPIs whenever the project tracks users, matching the
     * retention page. The dashboard overview skips it to avoid the rolling DAU/MAU
     * aggregations there.
     *
     * @return array<int, array{date: string, date_clear: string, stickiness: float|null, user_churn: float|null}>|null
     */
    private function engagementSeriesProp(bool $showRetention, Team $teamStats, StatsFilterDto $filters): ?array
    {
        if (! $showRetention && ! $this->request->routeIs('dashboard.metric')) {
            return null;
        }

        return $this->engagementSeries($teamStats, $filters);
    }

    /**
     * Time to convert (median visit -> registration days) lives only in the metric detail
     * view: surfacing it on the dashboard would grow the curated 3x3 overview. Like
     * engagement it is fully dimensionable (channel attribution is free), so no filter gate
     * beyond the visitors+users bits and the metric route. Null on the overview.
     *
     * @return array<int, array{date: string, date_clear: string, time_to_convert: float}>|null
     */
    private function timeToConvertSeriesProp(Team $teamStats, StatsFilterDto $filters): ?array
    {
        if (! $this->request->routeIs('dashboard.metric')) {
            return null;
        }

        if (! $this->projectTracksVisitorsAndUsers($teamStats, $filters)) {
            return null;
        }

        return $this->statsDetailsService->getTimeToConvertSeries($filters);
    }

    /**
     * Revenue churn lives on the revenue page (built there) and is only needed here for
     * the metric detail view of a churn KPI drilled into from there.
     *
     * @return array<int, array{date: string, date_clear: string, mrr: float, gross_churn_rate: float|null, net_churn_rate: float|null, quick_ratio: float|null}>|null
     */
    private function revenueChurnProp(Team $teamStats, StatsFilterDto $filters): ?array
    {
        if (! $this->isMetricFor(self::CHURN_KPIS)) {
            return null;
        }

        return $this->buildRevenueChurn($teamStats, $filters);
    }

    /**
     * The per-plan MRR table only appears on the metric detail view for an
     * MRR-sourced KPI, so it is computed only there (and gated on the
     * subscription bit inside buildPlanBreakdown).
     *
     * @return array<int, array{plan: string|null, subscribers: int, mrr: float}>|null
     */
    private function planBreakdown(Team $teamStats, StatsFilterDto $filters): ?array
    {
        if (! $this->isMetricFor(['mrr', 'subscribers', 'arps'])) {
            return null;
        }

        return $this->buildPlanBreakdown($teamStats, $filters);
    }

    /**
     * @param  array<int, string>  $kpis
     */
    private function isMetricFor(array $kpis): bool
    {
        return $this->request->routeIs('dashboard.metric')
            && in_array($this->request->route('kpi'), $kpis, true);
    }

    private function campaignSummary(StatsFilterDto $filters): ?array
    {
        if (empty($filters->campaign)) {
            return null;
        }

        // Spend has no per-dimension breakdown, so ROAS/CAC/net profit only hold for the
        // whole campaign: any other active filter shrinks revenue but not spend.
        if (array_keys($filters->toFilterArray()) !== ['campaign']) {
            return null;
        }

        $campaign = Campaign::find($filters->campaign);

        return $campaign === null ? null : $this->adSpendService->campaignSummary($filters, $campaign);
    }

    /**
     * Unlike the campaign summary, engagement is fully dimensionable (channel
     * attribution is free), so it has no toFilterArray gate and follows any filter.
     *
     * @return array<int, array{date: string, date_clear: string, stickiness: float|null, user_churn: float|null}>|null
     */
    private function engagementSeries(Team $teamStats, StatsFilterDto $filters): ?array
    {
        if (! $this->projectTracksUsers($teamStats, $filters)) {
            return null;
        }

        return $this->statsDetailsService->getEngagementTimeSeries($filters);
    }

    private function buildStats(Team $teamStats, StatsFilterDto $filters): Team
    {
        $teamStats->stats = $this->statsDetailsService->getStats($filters);

        if ($filters->comparison && $filters->rangeType !== StatsRangeType::YEAR) {
            $teamStats->statsPrev = array_values($this->statsDetailsService->getStats(
                StatsService::createPrevFiltersForComparison($filters),
            ));
        }

        return $teamStats;
    }

    /**
     * @return array{period_count: int, bucket_labels: array<int, string>, cohorts: array<int, array{cohort: string, idx: int, size: int, retention: array<int, float|null>}>}|null
     */
    private function buildRetentionCohorts(Team $teamStats, StatsFilterDto $filters): ?array
    {
        if (! $this->projectTracksUsers($teamStats, $filters)) {
            return null;
        }

        return $this->statsDetailsService->getRetentionCohorts($filters);
    }

    /**
     * Retention is login-based, so it is gated on the USERS bit (registrations
     * and logins). The "All projects" view passes when any project tracks users.
     */
    private function projectTracksUsers(Team $teamStats, StatsFilterDto $filters): bool
    {
        return $this->projectTracksBit($teamStats, $filters, Project::USAGE_USERS);
    }

    /**
     * Time to convert needs both a first visit and a registration, so it is gated on
     * the VISITORS and USERS bits together.
     */
    private function projectTracksVisitorsAndUsers(Team $teamStats, StatsFilterDto $filters): bool
    {
        return $this->projectTracksBit($teamStats, $filters, Project::USAGE_VISITORS)
            && $this->projectTracksBit($teamStats, $filters, Project::USAGE_USERS);
    }
}
