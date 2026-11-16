<?php

namespace App\Imports\Fathom;

use App\Services\LocationService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Turns a Fathom Analytics pageview export into the row formats of the raw visitor and
 * page view imports. Fathom only exports aggregates (date x dimensions x page with
 * pageviews / visits / uniques), so the visitors are synthesized:
 *
 * - every "visit" (Fathom: Entries) becomes one visitor that entered on the row's page,
 *   carrying the row's referrer/UTMs/device/location, so visitors, entry pages and
 *   sources match Fathom exactly per bucket
 * - "uniques" above the visits are other visitors of the same bucket that also opened
 *   the page (same source preferred), so unique viewers per page match too
 * - the remaining pageviews are repeat views of the row's viewers
 *
 * Visitor times are spread evenly over the bucket (day or hour). Hour of day and
 * bounce rate are therefore approximations, all totals are exact.
 */
class FathomExportMapper
{
    public const array DIMENSION_COLUMNS = [
        'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content',
        'referrer_hostname', 'referrer_pathname', 'hostname', 'ai_source',
        'browser', 'country', 'region', 'city', 'device_type', 'operating_system',
    ];

    public const array METRIC_COLUMNS = ['pageviews', 'visits', 'uniques', 'bounce_rate', 'avg_time_on_page'];

    public int $skippedViews = 0;

    public function __construct(private readonly LocationService $locationService) {}

    /**
     * @param  iterable<array<string, mixed>>  $rows
     * @return list<array{visitors: list<array<string, string|null>>, views: list<array{visitor_hash: string, page: string, views: int}>}>
     */
    public function map(iterable $rows, string $seed): array
    {
        $buckets = [];

        foreach ($rows as $row) {
            $buckets[(string) $row['date']][] = $row;
        }

        ksort($buckets);

        $mapped = [];

        foreach ($buckets as $bucket => $bucketRows) {
            $mapped[] = $this->mapBucket($bucket, $bucketRows, $seed);
        }

        return $mapped;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array{visitors: list<array<string, string|null>>, views: list<array{visitor_hash: string, page: string, views: int}>}
     */
    private function mapBucket(string $bucket, array $rows, string $seed): array
    {
        $visitors = [];
        $rowVisitors = [];

        foreach ($rows as $rowIndex => $row) {
            $attributes = $this->visitorAttributes($row);
            $sourceKey = $this->sourceKey($row);

            for ($i = 0; $i < $this->visits($row); $i++) {
                $hash = hash('xxh128', "{$seed}|{$bucket}|{$rowIndex}|{$i}");
                $visitors[$hash] = ['source' => $sourceKey, 'attributes' => $attributes];
                $rowVisitors[$rowIndex][] = $hash;
            }
        }

        $views = [];

        foreach ($rows as $rowIndex => $row) {
            $page = (string) $row['pathname'];
            $viewers = $rowVisitors[$rowIndex] ?? [];
            $uniques = max($this->int($row, 'uniques', count($viewers)), count($viewers));
            $pageviews = max($this->int($row, 'pageviews'), $uniques);

            $viewers = [...$viewers, ...$this->borrowViewers($visitors, $views, $page, $this->sourceKey($row), $uniques - count($viewers))];

            if ($viewers === []) {
                $this->skippedViews += $pageviews;

                continue;
            }

            foreach ($viewers as $hash) {
                $views[$hash][$page] = ($views[$hash][$page] ?? 0) + 1;
            }

            for ($extra = 0; $extra < $pageviews - count($viewers); $extra++) {
                $views[$viewers[$extra % count($viewers)]][$page]++;
            }
        }

        return [
            'visitors' => $this->visitorRows($bucket, $visitors),
            'views' => $this->viewItems($views),
        ];
    }

    /**
     * Picks visitors of the bucket that have not opened the page yet, same source first.
     *
     * @param  array<string, array{source: string, attributes: array<string, string|null>}>  $visitors
     * @param  array<string, array<string, int>>  $views
     * @return list<string>
     */
    private function borrowViewers(array $visitors, array $views, string $page, string $sourceKey, int $needed): array
    {
        if ($needed <= 0) {
            return [];
        }

        $sameSource = [];
        $otherSource = [];

        foreach ($visitors as $hash => $visitor) {
            if (isset($views[$hash][$page])) {
                continue;
            }

            if ($visitor['source'] === $sourceKey) {
                $sameSource[] = $hash;
            } else {
                $otherSource[] = $hash;
            }
        }

        return array_slice([...$sameSource, ...$otherSource], 0, $needed);
    }

    /**
     * @param  array<string, array{source: string, attributes: array<string, string|null>}>  $visitors
     * @return list<array<string, string|null>>
     */
    private function visitorRows(string $bucket, array $visitors): array
    {
        $start = CarbonImmutable::parse($bucket);
        $length = Str::contains($bucket, ':') ? 3600 : 86400;
        $count = count($visitors);
        $rows = [];
        $position = 0;

        foreach ($visitors as $hash => $visitor) {
            $offset = intdiv((2 * $position + 1) * $length, 2 * $count);

            $rows[] = [
                'visitor_hash' => $hash,
                'time' => $start->addSeconds($offset)->format('Y-m-d H:i:s'),
                ...$visitor['attributes'],
            ];

            $position++;
        }

        return $rows;
    }

    /**
     * @param  array<string, array<string, int>>  $views
     * @return list<array{visitor_hash: string, page: string, views: int}>
     */
    private function viewItems(array $views): array
    {
        $items = [];

        foreach ($views as $hash => $pages) {
            foreach ($pages as $page => $count) {
                $items[] = ['visitor_hash' => $hash, 'page' => (string) $page, 'views' => $count];
            }
        }

        return $items;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, string|null>
     */
    private function visitorAttributes(array $row): array
    {
        $countryCode = $this->value($row, 'country');
        $countrySuffix = $countryCode && strlen($countryCode) === 2 ? ' ('.Str::upper($countryCode).')' : '';

        return [
            'page_entry' => (string) $row['pathname'],
            'track_referer' => $this->value($row, 'referrer_hostname'),
            'track_source' => $this->value($row, 'utm_source') ?? $this->value($row, 'ai_source'),
            'track_medium' => $this->value($row, 'utm_medium'),
            'track_campaign' => $this->value($row, 'utm_campaign'),
            'track_term' => $this->value($row, 'utm_term'),
            'track_content' => $this->value($row, 'utm_content'),
            'location_country' => $countrySuffix ? $this->locationService->countryName((string) $countryCode).$countrySuffix : $countryCode,
            'location_region' => ($region = $this->value($row, 'region')) ? $region.$countrySuffix : null,
            'location_city' => ($city = $this->value($row, 'city')) ? $city.$countrySuffix : null,
            'device_type' => $this->deviceType($this->value($row, 'device_type')),
            'device_platform' => $this->value($row, 'operating_system'),
            'device_browser' => $this->value($row, 'browser'),
        ];
    }

    private function deviceType(?string $type): ?string
    {
        return match (Str::lower((string) $type)) {
            'desktop', 'laptop' => 'Desktop',
            'phone', 'mobile' => 'Mobile',
            'tablet' => 'Tablet',
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function sourceKey(array $row): string
    {
        return implode('|', array_map(fn (string $column): string => (string) $this->value($row, $column), [
            'referrer_hostname', 'utm_source', 'utm_medium', 'utm_campaign', 'ai_source',
        ]));
    }

    /**
     * Fathom names the entries metric "visits". Exports without it fall back to uniques.
     *
     * @param  array<string, mixed>  $row
     */
    private function visits(array $row): int
    {
        return $this->int($row, 'visits', $this->int($row, 'uniques'));
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function int(array $row, string $column, int $default = 0): int
    {
        $value = $row[$column] ?? null;

        return is_numeric($value) ? max(0, (int) $value) : $default;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function value(array $row, string $column): ?string
    {
        $value = trim((string) ($row[$column] ?? ''));

        return $value === '' ? null : $value;
    }
}
