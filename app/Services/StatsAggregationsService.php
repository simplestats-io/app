<?php

namespace App\Services;

use App\Builder\StatsAggregationsGroupedEventsSqlBuilder;
use App\Builder\StatsAggregationsGroupedPropertiesSqlBuilder;
use App\Builder\StatsAggregationsGroupedSqlBuilder;
use App\Builder\StatsAggregationsSqlBuilder;
use App\Builder\StatsFilterConditionsBuilder;
use App\Concerns\RunsFilteredStatsQueries;
use App\DTOs\StatsFilterDto;
use App\Enums\StatsRangeType;
use App\Enums\TrackablePerson;
use App\Helpers\AppHelper;
use App\Models\BaseStat;
use App\Models\Project;
use App\Models\StatsAggregation;
use App\Models\StatsCustomPropertyName;
use App\Models\Team;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Carbon\CarbonPeriodImmutable;
use Exception;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Stringable;

class StatsAggregationsService
{
    use RunsFilteredStatsQueries;

    public function __construct(
        private StatsFilterConditionsBuilder $filterConditionsBuilder,
    ) {}

    public function storeAggregatedData(Collection $data, StatsFilterDto $filters, string $kind = 'period'): void
    {
        if (! config('app.stats_aggregation_enabled')) {
            return;
        }

        // A restricted "all" would be stored as a team-wide row (project_id NULL),
        // which StatsIncrementService keeps incrementing with every project's stats.
        if (! $filters->projectId && AppHelper::accessibleProjectIds() !== null) {
            return;
        }

        $teamId = AppHelper::getTeamId();
        $filterHash = $filters->makeHash();
        $filterJson = json_encode($filters->toFilterArray());

        $upsertData = $data->map(fn ($item): array => [
            'team_id' => $teamId,
            'project_id' => $filters->projectId ?: null,
            'filter_hash' => $filterHash,
            'kind' => $kind,
            'filter' => $filterJson,
            'type' => $filters->rangeType,
            'time' => $item['date_clear'],
            'data' => json_encode($item),
            'selected_at' => now(),
        ]);

        StatsAggregation::upsert($upsertData->all(), uniqueBy: ['filter_hash', 'time', 'kind'], update: ['data', 'filter']);
    }

    /**
     * TODO the $limit should actually affect the query, but I want to filter by payments, regs, maus
     */
    public function getAggregatedGroupedData(StatsFilterDto $filters, string $statsType, string $statsSort = 'net', int $limit = 9, bool $withMrr = false, bool $withTimeToConvert = false, bool $withRetention = false, bool $withNau = false): Collection
    {
        $statsTypeSnake = str($statsType)->snake()->toString();

        $statsDBType = 'stats_'.$statsTypeSnake.'_id';
        $statsTable = 'stats_'.str($statsTypeSnake)->plural()->toString();

        // for track not but for referer we also want to know the Direct / Unknown
        $typeIdFallback = (! str($statsType)->startsWith('track') || $statsType === 'track_referer') ? '-1' : 'NULL';

        $project = Project::with('team:id,timezone')->find($filters->projectId);
        $trackablePerson = $this->getTrackablePersonByProject($project);

        // Only compute the MRR snapshot when the scope tracks recurring revenue, otherwise it
        // is wasted work returning 0s. The "All projects" scope (TRACK_ALL) has no concrete
        // Project row, so fall back to team-level usage (OR-merged across the team's projects,
        // matching how BuildsMrrProps gates every MRR surface). Without this the frontend would
        // sort by mrr while the column was skipped, producing an ORDER BY on a missing column.
        $withMrr = $withMrr
            && $trackablePerson === TrackablePerson::USER
            && $this->groupedScopeTracksUsage($project, [Project::USAGE_SUBSCRIPTION]);

        $withTimeToConvert = $withTimeToConvert
            && $trackablePerson === TrackablePerson::USER
            && $this->groupedScopeTracksUsage($project, [Project::USAGE_VISITORS, Project::USAGE_USERS]);

        $withRetention = $withRetention
            && $trackablePerson === TrackablePerson::USER
            && $this->groupedScopeTracksUsage($project, [Project::USAGE_USERS]);

        $withNau = $withNau
            && $trackablePerson === TrackablePerson::USER
            && $this->groupedScopeTracksUsage($project, [Project::USAGE_USERS]);

        if ($statsType === 'custom_event_name') {
            $sql = (new StatsAggregationsGroupedEventsSqlBuilder(
                $statsSort, $limit)
            )->build();
        } else {
            $statsSort = $this->resolveGroupedSort($statsSort, $trackablePerson, $withMrr, $withTimeToConvert, $withRetention, $withNau);

            $sql = (new StatsAggregationsGroupedSqlBuilder(
                $statsTable, $statsDBType, $statsSort, $typeIdFallback, $trackablePerson, $limit, $withMrr, $withTimeToConvert, $withNau,
                $withRetention ? $filters->rangeType->retentionCheckpointDays($filters->rangeAmount) : [])
            )->build();
        }

        $filterConditions = $this->getFilterConditions($filters);

        if ($withRetention) {
            $filterConditions['nowCap'] = now()->format(StatsRangeType::DATE_FORMAT);
        }

        if ($withMrr) {
            $filterConditions = StatsService::mrrSnapshotWindowConditions($filterConditions);
        }

        if ($trackablePerson === TrackablePerson::USER) {
            $tz = $project?->team?->timezone ?? config('app.timezone');
            $dauDates = $filters->rangeType->getDAUDates(CarbonImmutable::parse($filterConditions['endDateStart'], $tz));

            if (empty($dauDates)) {
                $filterConditions['dauEndDateStart'] = $filterConditions['endDateStart'];
                $filterConditions['dauEndDateEnd'] = $filterConditions['endDateEnd'];
            } else {
                $filterConditions['dauEndDateStart'] = $dauDates['start'];
                $filterConditions['dauEndDateEnd'] = $dauDates['end'];
            }
        }

        $sql = strtr($sql, $this->formatFilter($filterConditions));
        // echo nl2br($sql);die;

        $dataArray = $this->castNumericValues($this->getDataArray($sql));

        // hack to fix rtl stats grouped page names
        foreach ($dataArray as &$item) {
            if (Str::startsWith($item['name'], '/')) {
                $item['name'] = Str::replaceFirst('/', '', $item['name']).'/';
            }
        }

        return collect($dataArray);
    }

    /**
     * Group stats by the values of a single custom property (e.g. "ab_test"
     * bucketed into A / B). Runs as a standalone path next to
     * the static tracking dimensions because the grouping key is dynamic and the
     * data lives in the polymorphic stats_custom_properties pivot.
     *
     * Property lookups are project-scoped: a project view uses only that
     * project's name id, the "All projects" view includes every same-named id
     * of the team and merges the buckets by value string.
     */
    public function getAggregatedPropertyData(StatsFilterDto $filters, int $propertyNameId, string $statsSort = 'reg', int $limit = 9, bool $lifetime = false): Collection
    {
        $referenceName = StatsCustomPropertyName::find($propertyNameId);

        if ($referenceName === null) {
            return collect();
        }

        $nameIds = StatsCustomPropertyService::equivalentNameIds($referenceName, $filters->projectId);

        if ($nameIds->isEmpty()) {
            return collect();
        }

        $sql = (new StatsAggregationsGroupedPropertiesSqlBuilder(
            $statsSort, $limit, $lifetime
        ))->build();

        $filterConditions = $this->getFilterConditions($filters);
        $filterConditions['propertyNameIds'] = $nameIds->implode(',');

        $sql = strtr($sql, $this->formatFilter($filterConditions));

        $dataArray = $this->castNumericValues($this->getDataArray($sql));

        return collect($dataArray);
    }

    /**
     * Postgres returns numeric/float columns (net, MRR, ratios) as strings, which the
     * frontend formatters render as '-'. Cast them to floats so the columns show;
     * counts already arrive as ints.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    private function castNumericValues(array $rows): array
    {
        return array_map(function (array $item): array {
            foreach (['net', 'mrr'] as $money) {
                if (isset($item[$money])) {
                    $item[$money] = round((float) $item[$money], 2);
                }
            }

            foreach (['cr', 'arpv', 'arpu', 'arpdau', 'reg2dau', 'arps', 'time_to_convert', 'ret_c1', 'ret_c2', 'ret_c3'] as $ratio) {
                if (isset($item[$ratio])) {
                    $item[$ratio] = (float) $item[$ratio];
                }
            }

            return $item;
        }, $rows);
    }

    private function formatAggregatedData(Collection $aggregatedData): Collection
    {
        return $aggregatedData->keyBy('date_clear');
    }

    public function getAggregatedData(StatsFilterDto $filters): Collection
    {
        $aggregatedData = collect();
        $filterConditions = $this->getFilterConditions($filters);

        if (config('app.stats_aggregation_enabled')) {
            $aggregatedDataKeyedById = StatsAggregation::query()
                ->where('filter_hash', $filters->makeHash())
                ->where('kind', 'period')
                ->whereBetween('time', [
                    $filterConditions['startDateStart'],
                    $filterConditions['endDateEnd'],
                ])
                ->orderBy('time', 'desc')
                ->pluck('data', 'id');

            $this->refreshSelectedAt($aggregatedDataKeyedById->keys());

            $aggregatedData = $this->formatAggregatedData($aggregatedDataKeyedById);
        }

        if ($aggregatedData->isEmpty()) {
            $aggregatedData = $this->getRawData($filterConditions, $filters->projectId, $filters);
            $this->storeAggregatedData($aggregatedData, $filters);
        } else {
            $missingAggregatedData = $this->getMissingAggregatedData($aggregatedData, $filters);

            if ($missingAggregatedData->isNotEmpty()) {
                $aggregatedData = $aggregatedData->merge($missingAggregatedData);
                $this->storeAggregatedData($missingAggregatedData, $filters);
            }
        }

        return $aggregatedData->sortKeysDesc();
    }

    /**
     * Per-bucket cache for non-period series (MRR, engagement) that share the
     * bucket grid but not the additive period/lifetime model. Closed buckets are
     * served from (and lazily backfilled into) stats_aggregations under $kind. The
     * single open (current) bucket is always recomputed live and never stored: its
     * value drifts with wall-clock time (builders cap it at now via :nowCap), so a
     * stored value would freeze stale once the bucket closes. Closed buckets are
     * anchored to a fixed bucket end and only change on stat events in their window,
     * which the existing invalidateByStatsUpdate clears kind-agnostically.
     *
     * @param  callable(StatsFilterDto): array<int, array<string, mixed>>  $compute  Runs the metric builder for a (sub)range; each row carries a 'date_clear' key.
     * @return array<int, array<string, mixed>> Rows ordered by date_clear desc.
     */
    public function getCachedSeries(StatsFilterDto $filters, string $kind, callable $compute): array
    {
        if (! config('app.stats_aggregation_enabled')) {
            return $compute($filters);
        }

        $filterConditions = $this->getFilterConditions($filters);
        $dateFormatClear = $filters->rangeType->dateFormatClear()['php'];
        $openKey = trim($filters->rangeType->rangeStart(now())->format($dateFormatClear));

        $period = CarbonPeriodImmutable::create(
            $filterConditions['startDateStart'],
            '1 '.$filterConditions['intervalType']->toString(),
            $filterConditions['endDateStart']
        )->toArray();

        $cachedById = StatsAggregation::query()
            ->where('filter_hash', $filters->makeHash())
            ->where('kind', $kind)
            ->where('type', $filters->rangeType->value)
            ->whereBetween('time', [
                $filterConditions['startDateStart'],
                $filterConditions['endDateEnd'],
            ])
            ->pluck('data', 'id');

        $this->refreshSelectedAt($cachedById->keys());

        $byKey = $cachedById->keyBy(fn ($data): string => trim($data['date_clear']));

        $missingDates = [];
        $rangeIncludesOpen = false;

        foreach ($period as $date) {
            $key = trim(CarbonImmutable::parse($date)->format($dateFormatClear));

            if ($key === $openKey) {
                $rangeIncludesOpen = true;
            } elseif (! $byKey->has($key)) {
                $missingDates[] = CarbonImmutable::parse($date);
            }
        }

        if (! empty($missingDates)) {
            $fresh = collect();

            foreach ($this->groupContiguousDates($missingDates, $filterConditions['intervalType']) as $range) {
                $rangeFilters = $this->subRangeFilters($filters, count($range), end($range));

                // A sub-range can surface the open bucket at its edge; it is only ever recomputed live, never persisted.
                $fresh = $fresh->merge(
                    collect($compute($rangeFilters))->reject(fn (array $row): bool => trim($row['date_clear']) === $openKey)
                );
            }

            if ($fresh->isNotEmpty()) {
                $this->storeAggregatedData($fresh, $filters, $kind);

                foreach ($fresh as $row) {
                    $byKey->put(trim($row['date_clear']), $row);
                }
            }
        }

        if ($rangeIncludesOpen) {
            foreach ($compute($this->subRangeFilters($filters, 1, now())) as $row) {
                if (trim($row['date_clear']) === $openKey) {
                    $byKey->put($openKey, $row);
                }
            }
        }

        return $byKey->sortKeysDesc()->values()->all();
    }

    private function getFilterConditions(StatsFilterDto $filters): array
    {
        return $this->filterConditionsBuilder->build($filters);
    }

    private function subRangeFilters(StatsFilterDto $filters, int $rangeAmount, CarbonImmutable $rangeStart): StatsFilterDto
    {
        return new StatsFilterDto(...array_merge((array) $filters, [
            'rangeAmount' => $rangeAmount,
            'rangeStart' => $rangeStart,
        ]));
    }

    /**
     * Refresh selected_at so the GC (ClearOldStatsAggregations) keeps hot rows.
     * Throttled to avoid a write on every read: the GC window is 400 days, so an
     * hours-stale selected_at is irrelevant.
     */
    private function refreshSelectedAt(Collection $ids): void
    {
        StatsAggregation::whereIn('id', $ids)
            ->where('selected_at', '<', now()->subHours(6))
            ->update(['selected_at' => now()]);
    }

    /**
     * Detect missing aggregation entries (including internal gaps) and fetch raw data for them.
     * Previously, only boundary dates (before first / after last cached key) were detected,
     * which missed gaps in the middle of the cached range caused by partial invalidation.
     */
    private function getMissingAggregatedData(Collection $aggregatedData, StatsFilterDto $filters): Collection
    {
        $filterConditions = $this->getFilterConditions($filters);
        $dateFormatClear = $filters->rangeType->dateFormatClear()['php'];

        $period = CarbonPeriodImmutable::create(
            $filterConditions['startDateStart'],
            '1 '.$filterConditions['intervalType']->value,
            $filterConditions['endDateStart']
        )->toArray();

        $missingDates = [];
        foreach ($period as $date) {
            $dateKey = trim(CarbonImmutable::parse($date)->format($dateFormatClear));
            if (! $aggregatedData->has($dateKey)) {
                $missingDates[] = CarbonImmutable::parse($date);
            }
        }

        if (empty($missingDates)) {
            return collect();
        }

        $missingAggregatedData = collect();
        $ranges = $this->groupContiguousDates($missingDates, $filterConditions['intervalType']);

        foreach ($ranges as $range) {
            $rangeFilters = $this->subRangeFilters($filters, count($range), end($range));

            $missingAggregatedData = $missingAggregatedData->merge(
                $this->getRawData($this->getFilterConditions($rangeFilters), $rangeFilters->projectId, $rangeFilters)
            );
        }

        return $missingAggregatedData;
    }

    /**
     * @param  CarbonImmutable[]  $dates
     * @return CarbonImmutable[][]
     */
    private function groupContiguousDates(array $dates, Stringable $intervalType): array
    {
        usort($dates, fn (CarbonImmutable $a, CarbonImmutable $b): int => $a <=> $b);

        $ranges = [];
        $currentRange = [$dates[0]];
        $counter = count($dates);

        for ($i = 1; $i < $counter; $i++) {
            $expected = $currentRange[count($currentRange) - 1]->add(1, $intervalType->toString());

            if ($dates[$i]->equalTo($expected)) {
                $currentRange[] = $dates[$i];
            } else {
                $ranges[] = $currentRange;
                $currentRange = [$dates[$i]];
            }
        }
        $ranges[] = $currentRange;

        return $ranges;
    }

    public function getRawData(array $filterConditions, ?int $projectId, ?StatsFilterDto $filters = null): Collection
    {
        // Lazy carry-forward: instead of rescanning the whole history for the
        // lt_* baseline, reuse the accumulated lt_* of the cached bucket directly
        // before the range start (if present). Only the lt_* CTEs are affected;
        // the per-bucket pd_* values are still computed raw.
        $baselineSeed = $filters !== null ? $this->getCarryForwardBaseline($filters, $filterConditions) : null;

        $builder = new StatsAggregationsSqlBuilder(
            $this->getTrackablePersonByProject(Project::find($projectId)),
            seedLtBaseline: $baselineSeed !== null,
        );

        $sql = strtr($builder->build(), $this->formatFilter($filterConditions));
        // echo nl2br($sql);die;

        $dataArray = $this->getDataArray($sql);
        $this->applyContinuousLTAggregation($dataArray, $baselineSeed ?? []);

        return $this->formatAggregatedData(collect($dataArray));
    }

    /**
     * Resolve the lt_* baseline for a cold range from the cached predecessor
     * bucket instead of rescanning history. The bucket directly before the range
     * start already stores its accumulated lt_* (lifetime total up to and
     * including that bucket), which equals the sum of everything before the range
     * start, exactly what the lt_* CTEs would otherwise scan for. Returns null
     * (fall back to the full scan) when no such cached predecessor exists.
     *
     * @return array<string, int|float>|null
     */
    private function getCarryForwardBaseline(StatsFilterDto $filters, array $filterConditions): ?array
    {
        if (! config('app.stats_aggregation_enabled')) {
            return null;
        }

        $dateFormatClear = $filters->rangeType->dateFormatClear()['php'];

        $predecessorKey = trim(
            CarbonImmutable::parse($filterConditions['startDateStart'])
                ->sub(1, $filterConditions['intervalType']->toString())
                ->format($dateFormatClear)
        );

        $predecessor = StatsAggregation::query()
            ->where('filter_hash', $filters->makeHash())
            ->where('kind', 'period')
            ->where('type', $filters->rangeType->value)
            ->where('time', $predecessorKey)
            ->first(['id', 'data']);

        if ($predecessor === null) {
            return null;
        }

        $seed = [];
        foreach ($predecessor->data as $key => $value) {
            if (Str::startsWith($key, 'lt_')) {
                $seed[$key] = $value;
            }
        }

        return $seed ?: null;
    }

    /**
     * Accumulated lt_* carried forward from the cached predecessor bucket.
     *
     * @param  array<string, int|float>  $baselineSeed
     */
    private function applyContinuousLTAggregation(array &$dataArray, array $baselineSeed = []): void
    {
        $dataArray = array_reverse($dataArray);

        // Seed the oldest bucket's lt_* with the carried-forward baseline. The
        // forward propagation below then rolls it through the whole range. Only
        // the oldest row matters, every later row's lt_* gets overwritten by the
        // propagation step before it is read.
        if (! empty($baselineSeed) && ! empty($dataArray)) {
            foreach ($baselineSeed as $ltKey => $seedValue) {
                if (array_key_exists($ltKey, $dataArray[0])) {
                    $dataArray[0][$ltKey] += $seedValue;
                }
            }
        }

        $i = 0;
        foreach ($dataArray as $item) {
            foreach ($item as $key => $value) {
                if (Str::startsWith($key, 'lt_')) {
                    $pdKey = Str::replaceFirst('lt_', 'pd_', $key);
                    $dataArray[$i][$key] += $dataArray[$i][$pdKey];

                    $nextI = $i + 1;
                    if (isset($dataArray[$nextI])) {
                        $dataArray[$nextI][$key] = $dataArray[$i][$key];
                    }
                }
            }

            $i++;
        }

        $dataArray = array_reverse($dataArray);
    }

    /**
     * If a stat updates, every data above the stat time is invalid, so we need to delete it.
     * The same counts for data-imports.
     *
     * @throws Exception
     */
    public function invalidateByStatsUpdate(BaseStat $stat): void
    {
        foreach (StatsRangeType::cases() as $case) {
            $this->invalidateCaseByStatsUpdate($stat, $case);
        }
    }

    /*
     * it's important to convert the time to the corresponding range types (days, weeks, months)
     * for instance: if the update time is 2024-05-15, you'll also want to delete form its corresponding week,
     * which starts at 2024-05-13 (same counts for months)
     */
    public function invalidateCaseByStatsUpdate(BaseStat $stat, StatsRangeType $case): void
    {
        $this->invalidateCaseSince($stat->team_id, $stat->project_id, $stat->time, $case);
    }

    /**
     * Only the 'period' kind, for changes that cannot affect the MRR or engagement series.
     */
    public function invalidatePeriodsSince(int $teamId, int $projectId, CarbonInterface $time): void
    {
        foreach (StatsRangeType::cases() as $case) {
            $this->invalidateCaseSince($teamId, $projectId, $time, $case, 'period');
        }
    }

    private function invalidateCaseSince(int $teamId, int $projectId, CarbonInterface $time, StatsRangeType $case, ?string $kind = null): void
    {
        $start = $case->rangeStart($time)->format($case->dateFormatClear()['php']);

        StatsAggregation::where([
            ['time', '>=', $start],
            ['type', '=', $case->value],
        ])->when($kind, fn (Builder $query) => $query->where('kind', $kind))
            ->where(function ($query) use ($teamId, $projectId): void {
                // stats must be re-aggregated on a team basis
                $query->where(function ($query) use ($teamId): void {
                    $query->where('team_id', '=', $teamId)->where('project_id', '=', null);
                })
                // stats must be re-aggregated on project basis
                    ->orWhere(function ($query) use ($teamId, $projectId): void {
                        $query->where('team_id', '=', $teamId)->where('project_id', '=', $projectId);
                    });
            })->delete();
    }

    public function clearByProject(Project $project): void
    {
        StatsAggregation::where(function (Builder $query) use ($project): void {
            // stats must be re-aggregated on a team basis
            $query->where(function (\Illuminate\Contracts\Database\Query\Builder $query) use ($project): void {
                $query->where('team_id', '=', $project->team_id)->where('project_id', '=', null);
            })
            // stats must be re-aggregated on a project basis
                ->orWhere(function (\Illuminate\Contracts\Database\Query\Builder $query) use ($project): void {
                    $query->where('team_id', '=', $project->team_id)->where('project_id', '=', $project->id);
                });
        })->delete();
    }

    private function getTrackablePersonByProject(?Project $project): TrackablePerson
    {
        if (! $project instanceof Project || $project->hasUsages([Project::USAGE_USERS])) {
            return TrackablePerson::USER;
        }

        return TrackablePerson::VISITOR;
    }

    /**
     * Whether the grouped scope tracks all of the given usage bits. A concrete project is
     * checked directly; the "All projects" scope (null project) falls back to team-level
     * tracking, where each bit may be satisfied by a different project (OR-merged usage).
     *
     * @param  array<int, int>  $bits
     */
    private function groupedScopeTracksUsage(?Project $project, array $bits): bool
    {
        if ($project !== null) {
            return $project->hasUsages($bits);
        }

        $team = Team::find(AppHelper::getTeamId());

        if ($team === null) {
            return false;
        }

        foreach ($bits as $bit) {
            if (! $team->tracksUsage($bit)) {
                return false;
            }
        }

        return true;
    }

    /**
     * The grouped breakdown can only ORDER BY a column it actually selected. Available
     * columns depend on what the scope tracks (USER columns) and the opt-in snapshot columns
     * (MRR, time-to-convert), so a sort the current query does not produce (e.g. a stale
     * "mrr" sort carried over without the MRR opt-in) falls back to an always-present column.
     */
    private function resolveGroupedSort(string $statsSort, TrackablePerson $trackablePerson, bool $withMrr, bool $withTimeToConvert, bool $withRetention, bool $withNau): string
    {
        $allowed = ['name', 'net', 'visitors', 'arpv'];

        if ($trackablePerson === TrackablePerson::USER) {
            $allowed = array_merge($allowed, ['reg', 'cr', 'dau', 'arpu', 'arpdau', 'reg2dau']);
        }

        if ($withMrr) {
            $allowed = array_merge($allowed, ['mrr', 'subscribers', 'arps']);
        }

        if ($withTimeToConvert) {
            $allowed[] = 'time_to_convert';
        }

        if ($withRetention) {
            $allowed = array_merge($allowed, ['ret_c1', 'ret_c2', 'ret_c3']);
        }

        if ($withNau) {
            $allowed[] = 'nau';
        }

        return in_array($statsSort, $allowed, true) ? $statsSort : 'net';
    }
}
