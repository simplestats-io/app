<?php

namespace App\Services;

use App\DTOs\StatsFilterDto;
use App\Enums\ComparisonType;
use App\Enums\StatsRangeType;
use App\Models\Campaign;
use App\Models\Project;
use App\Models\StatsCustomEventName;
use App\Models\StatsCustomPropertyValue;
use App\Models\StatsDeviceBrowser;
use App\Models\StatsDevicePlatform;
use App\Models\StatsDeviceType;
use App\Models\StatsLocationCity;
use App\Models\StatsLocationCountry;
use App\Models\StatsLocationRegion;
use App\Models\StatsLogin;
use App\Models\StatsPageEntry;
use App\Models\StatsPayment;
use App\Models\StatsTrackCampaign;
use App\Models\StatsTrackContent;
use App\Models\StatsTrackMedium;
use App\Models\StatsTrackReferer;
use App\Models\StatsTrackSource;
use App\Models\StatsTrackTerm;
use App\Models\StatsUser;
use App\Models\StatsVisitor;
use App\Models\User;
use Carbon\CarbonImmutable;
use Exception;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class StatsService
{
    const NAU_LOGIN_COUNTER = 2;

    // trailing window (in days) for the "active paying user" metrics (pd_daapu):
    // a user counts as actively paying on a login day if a payment falls within
    // the preceding DAAPU_WINDOW_DAYS. Referenced by both the aggregation SQL and
    // the incremental aggregation guard in StatsIncrementService.
    const DAAPU_WINDOW_DAYS = 20;

    // grace window (in days) added to a subscription's interval before it counts
    // as churned in the MRR snapshot, so a renewal that lands a few days late is
    // not briefly dropped from MRR. Referenced by MrrAggregationService::computeMrrAtDate().
    const MRR_ACTIVE_GRACE_DAYS = 3;

    protected static array $statsModels = [
        StatsVisitor::class,
        StatsUser::class,
        StatsLogin::class,
        StatsPayment::class,
    ];

    protected static array $statsTypes = [
        'track' => [
            ['model' => StatsTrackReferer::class, 'type' => 'track_referer', 'title' => 'Referrer', 'icon' => 'favicon'],
            ['model' => StatsTrackSource::class, 'type' => 'track_source', 'title' => 'Source'],
            ['model' => StatsTrackMedium::class, 'type' => 'track_medium', 'title' => 'Medium'],
            ['model' => StatsTrackCampaign::class, 'type' => 'track_campaign', 'title' => 'Campaign'],
            ['model' => StatsTrackTerm::class, 'type' => 'track_term', 'title' => 'Term'],
            ['model' => StatsTrackContent::class, 'type' => 'track_content', 'title' => 'Content'],
        ],
        'page' => [
            ['model' => StatsPageEntry::class, 'type' => 'page_entry', 'title' => 'Entry Pages'],
        ],
        'location' => [
            ['model' => StatsLocationCountry::class, 'type' => 'location_country', 'title' => 'Countries', 'icon' => 'country'],
            ['model' => StatsLocationRegion::class, 'type' => 'location_region', 'title' => 'Regions', 'icon' => 'country'],
            ['model' => StatsLocationCity::class, 'type' => 'location_city', 'title' => 'Cities', 'icon' => 'country'],
        ],
        'device' => [
            ['model' => StatsDevicePlatform::class, 'type' => 'device_platform', 'title' => 'Platform', 'icon' => 'path'],
            ['model' => StatsDeviceBrowser::class, 'type' => 'device_browser', 'title' => 'Browser', 'icon' => 'path'],
            ['model' => StatsDeviceType::class, 'type' => 'device_type', 'title' => 'Size', 'icon' => 'path'],
        ],
        'event' => [
            ['model' => StatsCustomEventName::class, 'type' => 'custom_event_name', 'title' => 'Events'],
        ],
    ];

    /**
     * @throws Exception
     */
    public static function createPrevFiltersForComparison(StatsFilterDto $filters): StatsFilterDto
    {
        $prevFilters = clone $filters;

        $prevFilters->rangeStart = match ($filters->comparison) {
            ComparisonType::PERIOD->value => $prevFilters->rangeType->getPrevStartForPeriod($prevFilters->rangeStart, $prevFilters->rangeAmount),
            ComparisonType::CYCLE->value => $prevFilters->rangeType->getPrevStartForCycle($prevFilters->rangeStart),
            ComparisonType::YEAR->value => $prevFilters->rangeStart->copy()->subYear(),
            default => $prevFilters->rangeStart,
        };

        return $prevFilters;
    }

    public static function getStatsModels(): Collection
    {
        return collect(self::$statsModels);
    }

    public static function getStatsTypes(): Collection
    {
        return collect(self::$statsTypes);
    }

    public static function getStatsTypesFormatted(array $skip = []): Collection
    {
        return collect(self::$statsTypes)->map(fn ($types) => collect($types)->map(function (array $item) use ($skip): array {
            foreach ($skip as $key) {
                unset($item[$key]);
            }

            return $item;
        }));
    }

    public static function getStatsTypesFlatMap(array $skip = []): Collection
    {
        return collect(self::getStatsTypesFormatted($skip))->flatMap(fn ($group) => $group);
    }

    public static function asOptions(array $skip = []): Collection
    {
        return collect(self::getStatsTypesFlatMap($skip))->map(fn ($type): array => [
            'id' => $type['model'],
            'name' => ucfirst($type['title']),
        ])->values();
    }

    public static function getStatsTypesFlat(): Collection
    {
        return collect(self::getStatsTypesFormatted())->flatMap(fn ($item) => $item->pluck('type'));
    }

    public static function getFilterStatsTypeNames(StatsFilterDto $filters): Collection
    {
        $names = collect(self::getStatsTypesFormatted())->flatMap(fn ($item) => collect($item)->pluck('model', 'type'))->mapWithKeys(function ($model, $type) use ($filters): array {
            $camelType = Str::camel($type);
            if (! empty($filters->$camelType)) {
                return [$type => $model::find($filters->$camelType)?->name ?? 'Direct / Unknown'];
            }

            return [];
        });

        // Custom property filter chip: value plus its (humanised) property name.
        if (! empty($filters->propertyValue)) {
            $propertyValue = StatsCustomPropertyValue::with('name')->find($filters->propertyValue);

            if ($propertyValue !== null) {
                $names = $names->merge([
                    'property_value' => $propertyValue->value,
                    'property_value_title' => Str::title(str_replace('_', ' ', $propertyValue->name->name)),
                ]);
            }
        }

        if (! empty($filters->campaign)) {
            $campaign = Campaign::find($filters->campaign);

            if ($campaign !== null) {
                $names = $names->merge(['campaign' => $campaign->name]);
            }
        }

        return $names;
    }

    public static function formatFilters(StatsFilterDto $filters): array
    {
        $formatted = collect((array) $filters)
            ->mapWithKeys(fn ($value, $key): array => [Str::snake($key) => $value])
            ->merge(['range_start' => $filters->rangeStart->format('Y-m-d')])
            // Carbon instances must reach the frontend as naive strings, not objects.
            ->merge([
                'cohort_start' => $filters->cohortStart?->format('Y-m-d H:i:s'),
                'cohort_end' => $filters->cohortEnd?->format('Y-m-d H:i:s'),
            ])
            ->except('custom_preset_id')
            ->filter()
            ->toArray();

        if ($filters->customPresetId) {
            $formatted['preset'] = 'custom_'.$filters->customPresetId;
        }

        // project must have a default value...
        $formatted['project_id'] ??= 0;
        $formatted['comparison'] ??= 0;

        // The frontend/url side of the filters speaks public uuids, only the
        // backend works with the internal ids.
        if ($formatted['project_id'] !== 0) {
            $formatted['project_id'] = Project::whereKey($formatted['project_id'])->value('uuid') ?? 0;
        }
        if (! empty($formatted['campaign'])) {
            $formatted['campaign'] = Campaign::whereKey($formatted['campaign'])->value('uuid') ?? 0;
        }

        return $formatted;
    }

    public function safeDivision($numerator, $denominator): float|int
    {
        return $denominator == 0 ? 0 : $numerator / $denominator;
    }

    /**
     * Null until app:recalc-stats-starting-at has run for the project (nightly),
     * so callers can fall back instead of treating "now" as the stats start.
     */
    public function getStatsStartingAt(array $filter): ?CarbonImmutable
    {
        // filter to remove 0 key value pairs...
        $where = collect($filter)->filter()->toArray();

        $startingAt = Project::where($where)->min('stats_starting_at');

        return $startingAt === null ? null : CarbonImmutable::parse($startingAt);
    }

    /**
     * Compatible with an artisan command (no auth available, so all teams will be handled).
     * Else if auth exists, only the auth users team will be handled...
     */
    public function recalculateStatsStartingAt(): void
    {
        Project::all()->each(function (Project $project): void {
            $start = collect(StatsService::getStatsModels())->map(
                fn ($statsModel) => $statsModel::where(['project_id' => $project->id])->min('time')
            )->filter()->min();

            if ($start) {
                $project->update(['stats_starting_at' => $start]);
            }
        });
    }

    public static function getComparisonTypes(): array
    {
        return collect(ComparisonType::cases())->map(fn ($type): array => [
            'id' => $type->value,
            'name' => $type->getTitle(),
        ])->all();
    }

    public static function showFreePlanWarning(User $user): bool
    {
        return $user->team->owner->sparkPlan()->name === SubscriptionService::PLAN_FREE
            && $user->created_at->diffInDays(now()) > 7
            && ! ($user->settings['hideFreePlanWarning'] ?? false);
    }

    /**
     * The active-subscription window for an as-of MRR snapshot: a monthly sub counts
     * while its last payment is within a month (plus grace), a yearly one within a
     * year (plus grace). Single source of truth shared by the per-plan and
     * per-dimension MRR builders. Defaults the as-of date to the range end capped at
     * now, matching the headline MRR tile.
     *
     * @param  array<string, mixed>  $filterConditions
     * @return array<string, mixed>
     */
    public static function mrrSnapshotWindowConditions(array $filterConditions, ?string $asOfDate = null): array
    {
        $asOfDate ??= min($filterConditions['endDateEnd'], now()->format(StatsRangeType::DATE_FORMAT));
        $asOf = CarbonImmutable::parse($asOfDate);

        return array_merge($filterConditions, [
            'asOfDate' => $asOfDate,
            'monthWindowStart' => $asOf->subMonth()->subDays(self::MRR_ACTIVE_GRACE_DAYS)->format(StatsRangeType::DATE_FORMAT),
            'yearWindowStart' => $asOf->subYear()->subDays(self::MRR_ACTIVE_GRACE_DAYS)->format(StatsRangeType::DATE_FORMAT),
        ]);
    }
}
