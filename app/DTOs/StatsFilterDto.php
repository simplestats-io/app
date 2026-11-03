<?php

namespace App\DTOs;

use App\Enums\StatsRangePreset;
use App\Enums\StatsRangeType;
use App\Helpers\AppHelper;
use App\Http\Requests\StatsFilterRequest;
use App\Models\CustomStatsRangePreset;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\Str;

class StatsFilterDto
{
    const TRACK_ALL = 0;

    const TRACK_ORGANIC = -1;

    // array item order is part of hash, must be stable
    public const FILTER_DIMENSION_COLUMNS = [
        'trackReferer' => 'stats_track_referer_id',
        'trackSource' => 'stats_track_source_id',
        'trackMedium' => 'stats_track_medium_id',
        'trackCampaign' => 'stats_track_campaign_id',
        'trackTerm' => 'stats_track_term_id',
        'trackContent' => 'stats_track_content_id',
        'locationCountry' => 'stats_location_country_id',
        'locationRegion' => 'stats_location_region_id',
        'locationCity' => 'stats_location_city_id',
        'deviceType' => 'stats_device_type_id',
        'devicePlatform' => 'stats_device_platform_id',
        'deviceBrowser' => 'stats_device_browser_id',
        'pageEntry' => 'stats_page_entry_id',
    ];

    public const FILTER_SPECIAL_KEYS = [
        'customEventName' => 'custom_event_name',
        'propertyValue' => 'property_value',
        'campaign' => 'campaign',
    ];

    public function __construct(
        public int $projectId,
        public int $rangeAmount,
        public StatsRangeType $rangeType,
        public CarbonInterface $rangeStart,
        public string $comparison,
        public ?StatsRangePreset $preset = null,
        public ?int $customPresetId = null,
        public int $trackReferer = 0,
        public int $trackSource = 0,
        public int $trackMedium = 0,
        public int $trackCampaign = 0,
        public int $trackTerm = 0,
        public int $trackContent = 0,
        public int $locationCountry = 0,
        public int $locationRegion = 0,
        public int $locationCity = 0,
        public int $deviceType = 0,
        public int $devicePlatform = 0,
        public int $deviceBrowser = 0,
        public int $pageEntry = 0,
        public int $customEventName = 0,
        public int $propertyValue = 0,
        public int $campaign = 0,
        // Registration-cohort window: when set, every user-linked metric is scoped
        // to users who registered in [cohortStart, cohortEnd). Naive team-tz wall-clock
        // bounds matching the stats_users.time column, so no timezone shift is applied.
        public ?CarbonInterface $cohortStart = null,
        public ?CarbonInterface $cohortEnd = null,
        // Display label carried over from the retention table so the filter badge
        // renders the cohort exactly as that page does (matters for week ranges,
        // where the raw bucket start does not format to a readable week label).
        public ?string $cohortLabel = null,
    ) {}

    public static function fromRequest(StatsFilterRequest $request, ?int $projectId = null): self
    {
        $customPresetId = null;
        $effectiveProjectId = $projectId ?? $request->project_id ?? 0;

        if ($request->preset && Str::startsWith($request->preset, 'custom_')) {
            $customPresetId = (int) Str::after($request->preset, 'custom_');
            $customPreset = CustomStatsRangePreset::findOrFail($customPresetId);
            $request->merge($customPreset->toRange());
        } elseif ($request->preset && $request->preset !== StatsRangePreset::CUSTOM->value) {
            $request->merge(StatsRangePreset::from($request->preset)->range($effectiveProjectId));
        } elseif (! $request->hasAny(['range_type', 'range_amount', 'range_start', 'preset']) && empty(Request::query())) {
            $request->merge([
                ...StatsRangePreset::LAST_7_DAYS->range($effectiveProjectId),
                'preset' => StatsRangePreset::LAST_7_DAYS->value,
            ]);
        }

        $rangeType = StatsRangeType::from($request->range_type ?? StatsRangeType::DAY->value);
        $rangeAmount = $request->range_amount ?? $rangeType->defaultRangeAmount();
        $rangeStart = CarbonImmutable::parse($request->range_start ?? now());

        if ($rangeStart->isToday()) {
            $rangeStart = now();
        } else {
            $rangeStart = $rangeStart->endOfDay();
        }

        $cohortStart = $request->query('cohort_start');
        $cohortEnd = $request->query('cohort_end');
        $hasCohortWindow = $cohortStart && $cohortEnd;

        return new self(
            projectId: $effectiveProjectId,
            rangeAmount: $rangeAmount,
            rangeType: $rangeType,
            rangeStart: $rangeStart,
            comparison: $request->comparison ?? '0',
            preset: ($request->preset && ! Str::startsWith($request->preset, 'custom_'))
                ? StatsRangePreset::tryFrom($request->preset)
                : null,
            customPresetId: $customPresetId,
            trackReferer: $request->track_referer ?? 0,
            trackSource: $request->track_source ?? 0,
            trackMedium: $request->track_medium ?? 0,
            trackCampaign: $request->track_campaign ?? 0,
            trackTerm: $request->track_term ?? 0,
            trackContent: $request->track_content ?? 0,
            locationCountry: $request->location_country ?? 0,
            locationRegion: $request->location_region ?? 0,
            locationCity: $request->location_city ?? 0,
            deviceType: $request->device_type ?? 0,
            devicePlatform: $request->device_platform ?? 0,
            deviceBrowser: $request->device_browser ?? 0,
            pageEntry: $request->page_entry ?? 0,
            customEventName: $request->custom_event_name ?? 0,
            propertyValue: $request->property_value ?? 0,
            // Query bag, not $request->campaign: the campaigns/{campaign} route binds it to a model.
            campaign: (int) ($request->query('campaign') ?? 0),
            cohortStart: $hasCohortWindow ? CarbonImmutable::parse($cohortStart) : null,
            cohortEnd: $hasCohortWindow ? CarbonImmutable::parse($cohortEnd) : null,
            cohortLabel: $request->query('cohort_label') ?: null,
        );
    }

    /**
     * Whether a comparison period was requested and is supported for the
     * selected range type (year ranges have no comparable previous period).
     */
    public function shouldCompare(): bool
    {
        return $this->comparison && $this->comparison !== '0' && $this->rangeType !== StatsRangeType::YEAR;
    }

    public function makeHash(): string
    {
        $dimensions = [...array_keys(self::FILTER_DIMENSION_COLUMNS), ...array_keys(self::FILTER_SPECIAL_KEYS)];

        $hashParts = [
            AppHelper::getTeamId(),
            $this->projectId,
            $this->rangeType,
            ...array_map(fn (string $property) => $this->$property, $dimensions),
            $this->cohortStart?->format('Y-m-d H:i:s'),
            $this->cohortEnd?->format('Y-m-d H:i:s'),
        ];

        // A restricted member's "all" covers fewer projects than the team-wide
        // "all", so it must never share a cache entry with it. Only appended when
        // restricted, so every existing hash stays valid.
        $accessibleProjectIds = AppHelper::accessibleProjectIds();
        if ($accessibleProjectIds !== null) {
            $hashParts[] = $accessibleProjectIds;
        }

        return md5(json_encode($hashParts));
    }

    /**
     * @return array<string, int>
     */
    public function toFilterArray(): array
    {
        $filter = [];

        foreach ([...self::FILTER_DIMENSION_COLUMNS, ...self::FILTER_SPECIAL_KEYS] as $property => $key) {
            if ($this->$property !== self::TRACK_ALL) {
                $filter[$key] = $this->$property;
            }
        }

        return $filter;
    }
}
