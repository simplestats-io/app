<?php

namespace App\Services;

use App\DTOs\StatsFilterDto;
use App\Enums\StatsRangeType;
use App\Models\BaseStat;
use App\Models\StatsAggregation;
use App\Models\StatsLogin;
use App\Models\StatsPayment;
use App\Models\StatsUser;
use App\Models\StatsVisitor;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;

class StatsIncrementService
{
    // whitelist of metric keys that may be incremented
    private const array INCREMENTABLE_KEYS = [
        'pd_visitor', 'lt_visitor',
        'pd_reg', 'lt_reg',
        'pd_tau', 'pd_nau', 'lt_tau', 'pd_dau', 'pd_dapu', 'pd_daapu',
        'pd_gross', 'pd_net', 'lt_net',
        'pd_view', 'pd_viewer', 'pd_bounce',
    ];

    public function __construct(
        protected StatsAggregationsService $aggregations,
    ) {}

    public function handleCreated(BaseStat $stat): void
    {
        if (! config('app.stats_aggregation_enabled')) {
            return;
        }

        if (! $this->isIncrementable($stat)) {
            $this->aggregations->invalidateByStatsUpdate($stat);

            return;
        }

        foreach (StatsRangeType::cases() as $case) {
            $bucketStart = $case->rangeStart($stat->time);

            if (! $this->isOpenBucket($case, $bucketStart)) {
                $this->aggregations->invalidateCaseByStatsUpdate($stat, $case);

                continue;
            }

            $contributions = $this->getContributions($stat, $case, $bucketStart);

            if (empty($contributions)) {
                continue;
            }

            $this->applyToOpenBucket($stat, $case, $bucketStart, $contributions);
        }
    }

    /**
     * Views are additive and belong to exactly one bucket (the visitor's arrival), so unlike
     * the other stats they are incremented in closed buckets too, instead of invalidating
     * everything from the bucket onwards. A bucket that is not cached stays untouched, its
     * next lazy fill computes it from the raw tables.
     *
     * @param  list<array{attrs: array<string, mixed>, time: string, increments: array<string, int>}>  $contributions
     */
    public function handleViews(int $teamId, int $projectId, array $contributions): void
    {
        if (! config('app.stats_aggregation_enabled') || $contributions === []) {
            return;
        }

        foreach (StatsRangeType::cases() as $case) {
            $byBucket = [];

            foreach ($contributions as $contribution) {
                $bucketStart = $case->rangeStart(CarbonImmutable::parse($contribution['time']));
                $byBucket[$bucketStart->format('Y-m-d H:i:s')][] = $contribution;
            }

            foreach ($byBucket as $bucketStart => $bucketContributions) {
                $rows = $this->bucketRows($teamId, $projectId, $case, CarbonImmutable::parse($bucketStart));
                $this->applyToRows($rows, $bucketContributions);
            }
        }
    }

    /**
     * Fallback when the increments of a batch got lost (job died after its commit):
     * dropping the cached buckets of its visitors lets the next lazy fill recount them.
     *
     * @param  list<string>  $times
     */
    public function invalidateViewBuckets(int $teamId, int $projectId, array $times): void
    {
        if (! config('app.stats_aggregation_enabled')) {
            return;
        }

        foreach (StatsRangeType::cases() as $case) {
            $bucketStarts = collect($times)
                ->map(fn (string $time): string => $case->rangeStart(CarbonImmutable::parse($time))->format('Y-m-d H:i:s'))
                ->unique();

            foreach ($bucketStarts as $bucketStart) {
                $ids = $this->bucketRows($teamId, $projectId, $case, CarbonImmutable::parse($bucketStart))->pluck('id');

                if ($ids->isNotEmpty()) {
                    StatsAggregation::whereIn('id', $ids)->delete();
                }
            }
        }
    }

    private function isIncrementable(BaseStat $stat): bool
    {
        return match (true) {
            $stat instanceof StatsVisitor, $stat instanceof StatsUser, $stat instanceof StatsLogin => true,
            // the first payment retroactively changes pd_dapu/pd_pu/pd_napu; a repeat
            // payment that reopens the daapu window (no prior payment within it) moves
            // the user back into pd_daapu, which is not an incrementable metric either.
            $stat instanceof StatsPayment => (int) $stat->counter > 1 && $this->paymentStaysWithinDaapuWindow($stat),
            default => false,
        };
    }

    /**
     * @return array<int, array{attrs: array<string, mixed>, increments: array<string, int|float>}>
     */
    private function getContributions(BaseStat $stat, StatsRangeType $case, CarbonInterface $bucketStart): array
    {
        return match (true) {
            $stat instanceof StatsVisitor => [[
                'attrs' => $this->entityAttrs($stat),
                'increments' => ['pd_visitor' => 1, 'lt_visitor' => 1],
            ]],
            $stat instanceof StatsUser => [[
                'attrs' => $this->entityAttrs($stat),
                'increments' => ['pd_reg' => 1, 'lt_reg' => 1],
            ]],
            $stat instanceof StatsLogin => $this->loginContributions($stat, $case, $bucketStart),
            $stat instanceof StatsPayment => $this->paymentContributions($stat),
            default => [],
        };
    }

    /**
     * @return array<int, array{attrs: array<string, mixed>, increments: array<string, int|float>}>
     */
    private function loginContributions(StatsLogin $stat, StatsRangeType $case, CarbonInterface $bucketStart): array
    {
        $nau = StatsService::NAU_LOGIN_COUNTER;
        $increments = [];

        // total/new actives count every login with the nau counter (not distinct)
        if ((int) $stat->counter === $nau) {
            $increments['pd_tau'] = 1;
            $increments['pd_nau'] = 1;
            $increments['lt_tau'] = 1;
        }

        // dau counts any login (counter >= 1, including the registration login), distinct
        // per user: only the user's first login in the bucket increments it.
        $isFirstLoginInBucket = $this->isFirstActiveLoginInBucket($stat, $case, $bucketStart);

        if ($isFirstLoginInBucket) {
            $increments['pd_dau'] = 1;
        }

        if ($this->userHasPayment($stat)) {
            // dapu counts a user with a payment at any time, so like dau only their first login in the bucket counts
            if ($isFirstLoginInBucket) {
                $increments['pd_dapu'] = 1;
            }

            // daapu needs a payment within the window before the login itself, so an earlier login
            // of the same user in this bucket may not have qualified even though this one does
            if ($this->isWithinDaapuWindow($stat)
                && ($isFirstLoginInBucket || ! $this->hasOtherDaapuLoginInBucket($stat, $case, $bucketStart))) {
                $increments['pd_daapu'] = 1;
            }
        }

        if (empty($increments) || ! $user = $stat->statsUser) {
            return [];
        }

        return [[
            'attrs' => $this->entityAttrs($user),
            'increments' => $increments,
        ]];
    }

    /**
     * A payment contributes gross/net per attributed entity (user and/or visitor),
     * mirroring the aggregation query's UNION ALL: a filter matched via both paths is
     * counted twice, exactly as in the raw query.
     *
     * @return array<int, array{attrs: array<string, mixed>, increments: array<string, int|float>}>
     */
    private function paymentContributions(StatsPayment $stat): array
    {
        $increments = [
            'pd_gross' => (float) $stat->gross,
            'pd_net' => (float) $stat->net,
            'lt_net' => (float) $stat->net,
        ];

        $contributions = [];

        if ($stat->stats_user_id && $user = $stat->statsUser) {
            $contributions[] = ['attrs' => $this->entityAttrs($user), 'increments' => $increments];
        }

        if ($stat->stats_visitor_id && $visitor = $stat->statsVisitor) {
            $contributions[] = ['attrs' => $this->entityAttrs($visitor), 'increments' => $increments];
        }

        return $contributions;
    }

    /**
     * @param  array<int, array{attrs: array<string, mixed>, increments: array<string, int|float>}>  $contributions
     */
    private function applyToOpenBucket(BaseStat $stat, StatsRangeType $case, CarbonInterface $bucketStart, array $contributions): void
    {
        $this->applyToRows($this->bucketRows($stat->team_id, $stat->project_id, $case, $bucketStart), $contributions);
    }

    /**
     * @param  Collection<int, StatsAggregation>  $rows
     * @param  array<int, array{attrs: array<string, mixed>, increments: array<string, int|float>}>  $contributions
     */
    private function applyToRows(Collection $rows, array $contributions): void
    {
        $invalidateIds = [];
        $increments = [];

        foreach ($rows as $row) {
            $filter = $row->filter;

            if ($filter === null || $this->hasSpecialFilterKey($filter)) {
                $invalidateIds[] = $row->id;

                continue;
            }

            foreach ($contributions as $contribution) {
                if (! $this->filterMatches($filter, $contribution['attrs'])) {
                    continue;
                }

                foreach ($contribution['increments'] as $key => $amount) {
                    $increments[$row->id][$key] = ($increments[$row->id][$key] ?? 0) + $amount;
                }
            }
        }

        if (! empty($invalidateIds)) {
            StatsAggregation::whereIn('id', $invalidateIds)->delete();
        }

        foreach ($increments as $id => $keys) {
            foreach ($keys as $key => $amount) {
                $this->applyIncrement($id, $key, $amount);
            }
        }
    }

    /**
     * @return Collection<int, StatsAggregation>
     */
    private function bucketRows(int $teamId, int $projectId, StatsRangeType $case, CarbonInterface $bucketStart): Collection
    {
        return $this->withinBucket(StatsAggregation::query(), $case, $bucketStart)
            ->where('type', $case->value)
            ->where('kind', 'period')
            ->where('team_id', $teamId)
            ->where(function (Builder $query) use ($projectId): void {
                $query->whereNull('project_id')->orWhere('project_id', $projectId);
            })
            ->get(['id', 'filter']);
    }

    /**
     * @param  array<string, int>  $filter
     */
    private function hasSpecialFilterKey(array $filter): bool
    {
        return Arr::hasAny($filter, [...array_values(StatsFilterDto::FILTER_SPECIAL_KEYS), StatsFilterDto::FILTER_COHORT_KEY]);
    }

    /**
     * An empty filter (the unfiltered row) always matches; TRACK_ORGANIC (-1) requires null.
     *
     * @param  array<string, int>  $filter
     * @param  array<string, mixed>  $attrs
     */
    private function filterMatches(array $filter, array $attrs): bool
    {
        foreach ($filter as $column => $value) {
            $eventValue = $attrs[$column] ?? null;

            if ((int) $value === StatsFilterDto::TRACK_ORGANIC) {
                if ($eventValue !== null) {
                    return false;
                }

                continue;
            }

            if ($eventValue === null || (int) $eventValue !== (int) $value) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    private function entityAttrs(Model $entity): array
    {
        $attrs = [];

        foreach (StatsFilterDto::FILTER_DIMENSION_COLUMNS as $column) {
            $attrs[$column] = $entity->{$column};
        }

        return $attrs;
    }

    private function isOpenBucket(StatsRangeType $case, CarbonInterface $bucketStart): bool
    {
        $format = $case->dateFormatClear()['php'];

        return $bucketStart->format($format) === $case->rangeStart(now())->format($format);
    }

    private function bucketEnd(StatsRangeType $case, CarbonInterface $bucketStart): CarbonInterface
    {
        return match ($case) {
            StatsRangeType::HOUR => $bucketStart->addHour(),
            StatsRangeType::DAY => $bucketStart->addDay(),
            StatsRangeType::WEEK => $bucketStart->addWeek(),
            StatsRangeType::MONTH => $bucketStart->addMonth(),
            StatsRangeType::YEAR => $bucketStart->addYear(),
        };
    }

    private function withinBucket(Builder $query, StatsRangeType $case, CarbonInterface $bucketStart): Builder
    {
        return $query
            ->where('time', '>=', $bucketStart->format('Y-m-d H:i:s'))
            ->where('time', '<', $this->bucketEnd($case, $bucketStart)->format('Y-m-d H:i:s'));
    }

    // stats_user_id is only unique per project (stats_users PK is [id, project_id]),
    // so every entity lookup must pin project_id explicitly. The global HasTeamScope/
    // HasProjectScope only narrow this further when their (queue-propagated) context
    // is present, which is not guaranteed for every code path that creates a stat.
    private function userHasPayment(StatsLogin $stat): bool
    {
        return StatsPayment::where('stats_user_id', $stat->stats_user_id)
            ->where('project_id', $stat->project_id)
            ->exists();
    }

    /**
     * Whether the user already had a payment within the daapu window directly
     * before this one. If so, they were already an "active paying user" and this
     * repeat payment does not change pd_daapu, so the open bucket may be incremented.
     */
    private function paymentStaysWithinDaapuWindow(StatsPayment $stat): bool
    {
        return StatsPayment::where('stats_user_id', $stat->stats_user_id)
            ->where('project_id', $stat->project_id)
            ->where('id', '!=', $stat->id)
            ->where('time', '>=', $stat->time->subDays(StatsService::DAAPU_WINDOW_DAYS)->format('Y-m-d H:i:s'))
            ->where('time', '<=', $stat->time->format('Y-m-d H:i:s'))
            ->exists();
    }

    private function isWithinDaapuWindow(StatsLogin $stat): bool
    {
        // BaseStat casts time to datetime, but Larastan does not see the inherited cast on StatsLogin
        /** @var CarbonInterface $loginTime */
        $loginTime = $stat->getAttribute('time');

        return StatsPayment::where('stats_user_id', $stat->stats_user_id)
            ->where('project_id', $stat->project_id)
            ->where('time', '>=', $loginTime->subDays(StatsService::DAAPU_WINDOW_DAYS)->format('Y-m-d H:i:s'))
            ->where('time', '<=', $loginTime->format('Y-m-d H:i:s'))
            ->exists();
    }

    private function hasOtherDaapuLoginInBucket(StatsLogin $stat, StatsRangeType $case, CarbonInterface $bucketStart): bool
    {
        return $this->withinBucket(
            StatsLogin::where('stats_user_id', $stat->stats_user_id)
                ->where('project_id', $stat->project_id)
                ->whereKeyNot($stat->id),
            $case,
            $bucketStart,
        )->whereExists(fn (QueryBuilder $query) => $query
            ->from('stats_payments')
            ->whereColumn('stats_payments.stats_user_id', 'stats_logins.stats_user_id')
            ->whereColumn('stats_payments.project_id', 'stats_logins.project_id')
            ->whereRaw('stats_payments.time >= stats_logins.time - make_interval(days => ?)', [StatsService::DAAPU_WINDOW_DAYS])
            ->whereColumn('stats_payments.time', '<=', 'stats_logins.time')
        )->exists();
    }

    private function isFirstActiveLoginInBucket(StatsLogin $stat, StatsRangeType $case, CarbonInterface $bucketStart): bool
    {
        return $this->withinBucket(
            StatsLogin::where('stats_user_id', $stat->stats_user_id)
                ->where('project_id', $stat->project_id),
            $case,
            $bucketStart,
        )->count() === 1;
    }

    private function applyIncrement(int $id, string $key, int|float $amount): void
    {
        if ($amount == 0 || ! in_array($key, self::INCREMENTABLE_KEYS, true)) {
            return;
        }

        StatsAggregation::where('id', $id)->incrementJsonValue('data', $key, $amount);
    }
}
