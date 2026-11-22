<?php

namespace App\Services;

use App\DTOs\StatsFilterDto;
use App\Enums\StatsRangeType;
use App\Models\Project;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class StatsDetailsService
{
    private const array PAGE_VIEW_KEYS = ['pd_view', 'pd_viewer', 'pd_bounce', 'pd_vpv', 'pd_bounce_rate'];

    public function __construct(
        private readonly StatsService $statsService,
        private readonly StatsAggregationsService $statsAggregationsService,
        private readonly MrrAggregationService $mrrAggregationService,
        private readonly RetentionAggregationService $retentionAggregationService,
        private readonly EngagementAggregationService $engagementAggregationService,
        private readonly FunnelTimingService $funnelTimingService,
    ) {}

    public function getStats(StatsFilterDto $filters): array
    {
        $stats = $this->getRaw($filters);
        $this->applyStatsDetails($stats);
        $this->blankUntrackedPageViews($stats, $filters);

        return $stats;
    }

    public function getRaw(StatsFilterDto $filters): array
    {
        return $this->statsAggregationsService->getAggregatedData($filters)->toArray();
    }

    /**
     * @return array{mrr: float, arr: float, mrr_prev: float}
     */
    public function getMrrSnapshot(StatsFilterDto $filters): array
    {
        return $this->mrrAggregationService->getMrrSnapshot($filters);
    }

    /**
     * @return array<int, array{plan: string|null, subscribers: int, mrr: float}>
     */
    public function getMrrByPlan(StatsFilterDto $filters): array
    {
        return $this->mrrAggregationService->getMrrByPlan($filters);
    }

    /**
     * @return array<int, array{date: string, date_clear: string, mrr: float, subscribers: int}>
     */
    public function getMrrTimeSeries(StatsFilterDto $filters): array
    {
        return $this->mrrAggregationService->getMrrTimeSeries($filters);
    }

    /**
     * @return array<int, array{date: string, date_clear: string, starting_mrr: float, new: float, reactivation: float, expansion: float, contraction: float, churn: float, ending_mrr: float, net_change: float}>
     */
    public function getMrrMovements(StatsFilterDto $filters): array
    {
        return $this->mrrAggregationService->getMrrMovements($filters);
    }

    /**
     * @return array{nrr: float|null, grr: float|null, quick_ratio: float|null, starting_mrr: float, ending_mrr: float, new: float, reactivation: float, expansion: float, contraction: float, churn: float}
     */
    public function getMrrRetention(StatsFilterDto $filters): array
    {
        return $this->mrrAggregationService->getMrrRetention($filters);
    }

    /**
     * @return array<int, array{date: string, date_clear: string, mrr: float, gross_churn_rate: float|null, net_churn_rate: float|null, quick_ratio: float|null}>
     */
    public function getRevenueChurn(StatsFilterDto $filters): array
    {
        return $this->mrrAggregationService->getRevenueChurn($filters);
    }

    public function getLtv(StatsFilterDto $filters): ?float
    {
        return $this->mrrAggregationService->getLtv($filters);
    }

    /**
     * @return array{arps: float, monthly_churn: float|null, ltv: float|null}|null
     */
    public function getSubscriptionEconomics(StatsFilterDto $filters): ?array
    {
        return $this->mrrAggregationService->getSubscriptionEconomics($filters);
    }

    public function getTimeToPay(StatsFilterDto $filters): ?float
    {
        return $this->funnelTimingService->getTimeToPay($filters);
    }

    /**
     * @return array<int, array{date: string, date_clear: string, time_to_convert: float}>
     */
    public function getTimeToConvertSeries(StatsFilterDto $filters): array
    {
        return $this->funnelTimingService->getTimeToConvertSeries($filters);
    }

    /**
     * @return array{period_count: int, bucket_labels: array<int, string>, cohorts: array<int, array{cohort: string, idx: int, size: int, retention: array<int, float|null>}>}
     */
    public function getRetentionCohorts(StatsFilterDto $filters): array
    {
        return $this->retentionAggregationService->getRetentionCohorts($filters);
    }

    /**
     * @return array<int, array{date: string, date_clear: string, stickiness: float|null, user_churn: float|null}>
     */
    public function getEngagementTimeSeries(StatsFilterDto $filters): array
    {
        return $this->engagementAggregationService->getEngagementTimeSeries($filters);
    }

    /**
     * ->values()->toArray() makes sure the indexes are int-numeric and not
     * string-numeric (which would result in a js object). This happens cause
     * of the reject at the end of the aggregation methods.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getGroupedData(
        StatsFilterDto $filters,
        string $statsType,
        int $limit,
        ?string $sort = null,
        ?int $propertyNameId = null,
        bool $lifetime = false,
        bool $withMrr = false,
        bool $withTimeToConvert = false,
        bool $withRetention = false,
        bool $withNau = false,
        bool $withViews = false,
    ): array {
        if ($statsType === StatsService::TOP_PAGES_TYPE) {
            return $this->statsAggregationsService->getTopPagesData($filters, $sort ?? 'views', $limit)->values()->all();
        }

        if ($statsType === 'property') {
            return $this->statsAggregationsService->getAggregatedPropertyData(
                $filters,
                (int) $propertyNameId,
                $sort ?? 'reg',
                $limit,
                $lifetime,
                $withViews,
            )->values()->toArray();
        }

        return $this->statsAggregationsService->getAggregatedGroupedData(
            $filters,
            $statsType,
            $sort,
            $limit,
            $withMrr,
            $withTimeToConvert,
            $withRetention,
            $withNau,
            $withViews,
        )->values()->toArray();
    }

    private function applyStatsDetails(array &$stats): void
    {
        foreach ($stats as &$stat) {
            if (isset($stat['pd_reg'])) {
                $stat['pd_cr'] = round($this->statsService->safeDivision($stat['pd_reg'], $stat['pd_visitor']) * 100, 2);
                $stat['pd_arpdau'] = $this->statsService->safeDivision($stat['pd_net'], $stat['pd_dau']);
                $stat['lt_reg2dau'] = round($this->statsService->safeDivision($stat['pd_dau'], $stat['lt_reg']) * 100, 2);
                $stat['lt_reg2pu'] = round($this->statsService->safeDivision($stat['lt_pu'], $stat['lt_reg']) * 100, 2);
                $stat['lt_reg2tau'] = round($this->statsService->safeDivision($stat['lt_tau'], $stat['lt_reg']) * 100, 2);
                $stat['lt_arpu'] = round($this->statsService->safeDivision($stat['lt_net'], $stat['lt_reg']), 2);
                $stat['lt_arppu'] = round($this->statsService->safeDivision($stat['lt_net'], $stat['lt_pu']), 2);
            }

            $stat['pd_gross'] = round($stat['pd_gross'], 2);
            $stat['pd_net'] = round($stat['pd_net'], 2);
            $stat['lt_net'] = round($stat['lt_net'], 2);

            // Ratios over viewers, not visitors: visitors without a reported view must not dilute them.
            if (isset($stat['pd_view'])) {
                $stat['pd_vpv'] = round($this->statsService->safeDivision($stat['pd_view'], $stat['pd_viewer']), 2);
                $stat['pd_bounce_rate'] = round($this->statsService->safeDivision($stat['pd_bounce'], $stat['pd_viewer']) * 100, 2);
            }

            $stat['pd_fee'] = $stat['pd_gross'] - $stat['pd_net'];
            $stat['lt_arpv'] = round($this->statsService->safeDivision($stat['lt_net'], $stat['lt_visitor']), 2);
            unset($stat['pd_tau'], $stat['pd_pu']);
        } unset($stat);
    }

    /**
     * Buckets that ended before the first tracked view were never measured, so they report
     * null instead of a 0 that would read as "nobody opened a page".
     *
     * @param  array<int|string, array<string, mixed>>  $stats
     */
    private function blankUntrackedPageViews(array &$stats, StatsFilterDto $filters): void
    {
        $since = $this->getPageViewsState($filters)['since'];

        if ($since === null) {
            return;
        }

        $since = CarbonImmutable::parse($since);

        foreach ($stats as &$stat) {
            if (! array_key_exists('pd_view', $stat)) {
                continue;
            }

            $bucketEnd = CarbonImmutable::parse(trim((string) $stat['date_clear']))->add(1, $filters->rangeType->value);

            if ($bucketEnd <= $since) {
                foreach (self::PAGE_VIEW_KEYS as $key) {
                    $stat[$key] = null;
                }
            }
        } unset($stat);
    }

    /**
     * @return array{visible: bool, since: ?string, hasVisitors: bool}
     */
    public function getPageViewsState(StatsFilterDto $filters): array
    {
        return $this->statsAggregationsService->pageViewsState($filters);
    }

    public function getRangeTypes(): Collection
    {
        return collect(StatsRangeType::values())->transform(fn ($name, $key): array => [
            'id' => $name,
            'name' => ucfirst($name),
        ]);
    }

    public function getAllFilterFor(string $type, ?int $usage = null, ?int $flags = null): array
    {
        return [
            'id' => StatsFilterDto::TRACK_ALL,
            'uuid' => StatsFilterDto::TRACK_ALL,
            'name' => 'All',
            'usage' => $usage ?? Project::getUsageBitsCount(),
            'flags' => $flags ?? 0,
        ];
    }
}
