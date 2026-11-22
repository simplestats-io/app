<?php

namespace App\Enums;

use App\Services\StatsService;
use LaracraftTech\LaravelUsefulAdditions\Traits\UsefulEnums;

enum StatsRangePreset: string
{
    use UsefulEnums;

    case TODAY = 'today';
    case YESTERDAY = 'yesterday';
    case LAST_7_DAYS = 'last_7_days';
    case LAST_30_DAYS = 'last_30_days';
    case LAST_12_WEEKS = 'last_12_weeks';
    case LAST_6_MONTHS = 'last_6_months';
    case THIS_MONTH = 'this_month';
    case LAST_MONTH = 'last_month';
    case THIS_YEAR = 'this_year';
    case LAST_YEAR = 'last_year';
    case ALL_TIME = 'all_time';
    case CUSTOM = 'custom';

    public static function toOptions(): array
    {
        return collect(self::cases())->map(fn (StatsRangePreset $preset): array => [
            'id' => $preset->value,
            'name' => $preset->title(),
            'sortWeight' => $preset->sortWeight(),
        ])->values()->all();
    }

    public function sortWeight(): int
    {
        return match ($this) {
            self::TODAY => 100,
            self::YESTERDAY => 200,
            self::LAST_7_DAYS => 700,
            self::LAST_30_DAYS => 3000,
            self::LAST_12_WEEKS => 8400,
            self::LAST_6_MONTHS => 18000,
            // Calendar presets come after all rolling ranges up to about a year
            // (52 weeks = 41600), so custom rolling presets stay grouped together.
            self::THIS_MONTH => 42000,
            self::LAST_MONTH => 42100,
            self::THIS_YEAR => 42200,
            self::LAST_YEAR => 42300,
            self::ALL_TIME => 99998,
            self::CUSTOM => 99999,
        };
    }

    public function title(): string
    {
        return match ($this) {
            self::TODAY => 'Today',
            self::YESTERDAY => 'Yesterday',
            self::LAST_7_DAYS => 'Last 7 Days',
            self::LAST_30_DAYS => 'Last 30 Days',
            self::LAST_12_WEEKS => 'Last 12 Weeks',
            self::LAST_6_MONTHS => 'Last 6 Months',
            self::THIS_MONTH => 'This Month',
            self::LAST_MONTH => 'Last Month',
            self::THIS_YEAR => 'This Year',
            self::LAST_YEAR => 'Last Year',
            self::ALL_TIME => 'All Time',
            self::CUSTOM => 'Custom',
        };
    }

    public function range(?int $projectId): array
    {
        return match ($this) {
            self::TODAY => [
                'range_start' => today()->format(StatsRangeType::DATE_FORMAT),
                'range_amount' => today()->diffInHours(now()) + 1,
                'range_type' => StatsRangeType::HOUR->value,
            ],
            self::YESTERDAY => [
                'range_start' => now()->subDay()->startOfDay()->format(StatsRangeType::DATE_FORMAT),
                'range_amount' => 24,
                'range_type' => StatsRangeType::HOUR->value,
            ],
            self::LAST_7_DAYS => [
                'range_start' => now()->format(StatsRangeType::DATE_FORMAT),
                'range_amount' => 7,
                'range_type' => StatsRangeType::DAY->value,
            ],
            self::LAST_30_DAYS => [
                'range_start' => now()->format(StatsRangeType::DATE_FORMAT),
                'range_amount' => 30,
                'range_type' => StatsRangeType::DAY->value,
            ],
            self::LAST_12_WEEKS => [
                'range_start' => now()->format(StatsRangeType::DATE_FORMAT),
                'range_amount' => 12,
                'range_type' => StatsRangeType::WEEK->value,
            ],
            self::LAST_6_MONTHS => [
                'range_start' => now()->format(StatsRangeType::DATE_FORMAT),
                'range_amount' => now()->subMonths(6)->startOfMonth()->diffInMonths(now()),
                'range_type' => StatsRangeType::MONTH->value,
            ],
            self::THIS_MONTH => [
                'range_start' => now()->format(StatsRangeType::DATE_FORMAT),
                'range_amount' => now()->startOfMonth()->diffInDays(now()) + 1,
                'range_type' => StatsRangeType::DAY->value,
            ],
            self::LAST_MONTH => [
                'range_start' => now()->subMonth()->endOfMonth()->format(StatsRangeType::DATE_FORMAT),
                'range_amount' => now()->subMonth()->startOfMonth()->diffInDays(now()->subMonth()->endOfMonth()) + 1,
                'range_type' => StatsRangeType::DAY->value,
            ],
            self::THIS_YEAR => [
                'range_start' => now()->format(StatsRangeType::DATE_FORMAT),
                'range_amount' => now()->startOfYear()->diffInMonths(now()) + 1,
                'range_type' => StatsRangeType::MONTH->value,
            ],
            self::LAST_YEAR => [
                'range_start' => now()->subYear()->endOfYear()->format(StatsRangeType::DATE_FORMAT),
                'range_amount' => now()->subYear()->startOfYear()->diffInMonths(now()->subYear()->endOfYear()) + 1,
                'range_type' => StatsRangeType::MONTH->value,
            ],
            self::ALL_TIME => $this->getAllTimeRange($projectId),
            self::CUSTOM => [
                'range_start' => null,
                'range_amount' => null,
                'range_type' => null,
            ],
        };
    }

    private function getAllTimeRange(?int $projectId): array
    {
        $user = auth()->user();

        $statsStartingAt = resolve(StatsService::class)->getStatsStartingAt([
            'team_id' => $user->team->id,
            'id' => $projectId,
        ]) ?? now()->subYears(1)->startOfYear();

        $now = now();

        $allTimeType = StatsRangeType::MONTH;
        $allTimeRange = $statsStartingAt->diffInMonths($now) + 2;

        // If the range is more than 12 months, switch to year
        if ($allTimeRange > (12 * 4)) {
            $allTimeType = StatsRangeType::YEAR;
            $allTimeRange = $statsStartingAt->diffInYears($now) + 3;
        }

        return [
            'range_start' => $now->format('Y-m-d'),
            'range_amount' => $allTimeRange,
            'range_type' => $allTimeType->value,
        ];
    }
}
