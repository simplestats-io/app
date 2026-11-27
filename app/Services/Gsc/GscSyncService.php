<?php

namespace App\Services\Gsc;

use App\Helpers\PagePathHelper;
use App\Jobs\SyncGscDataJob;
use App\Models\GscConnection;
use App\Models\StatsKeywordDaily;
use App\Models\StatsPage;
use App\Models\StatsTrackKeyword;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class GscSyncService
{
    public const int BACKFILL_MONTHS = 16;

    /**
     * Small enough to keep one window of a large property in memory.
     */
    public const int BACKFILL_CHUNK_DAYS = 7;

    /**
     * Search Console keeps revising the last days, so every daily run fetches them again.
     */
    public const int RESYNC_DAYS = 3;

    private const int NAME_LENGTH = 252;

    public function __construct(private readonly GscApiService $api) {}

    /**
     * Independent jobs instead of a chain: the windows do not overlap, and one failing
     * window must not cancel the rest of the history. Newest first, so the dashboard
     * fills from the recent end. The daily sync continues from the fixed end date.
     */
    public function queueBackfill(GscConnection $connection): void
    {
        $end = $this->lastAvailableDate();

        $connection->update(['last_synced_until_date' => $end->toDateString()]);

        foreach (array_reverse($this->windows($end->subMonths(self::BACKFILL_MONTHS), $end)) as [$start, $windowEnd]) {
            dispatch(new SyncGscDataJob($connection, $start->toDateString(), $windowEnd->toDateString()));
        }
    }

    public function syncRecent(GscConnection $connection): void
    {
        $end = $this->lastAvailableDate();
        $start = $connection->last_synced_until_date
            ? CarbonImmutable::parse($connection->last_synced_until_date->toDateString())->subDays(self::RESYNC_DAYS)
            : $end->subDays(30);

        // After an outage the gap can be long, windows keep each fetch small.
        foreach ($this->windows($start->min($end), $end) as [$windowStart, $windowEnd]) {
            $this->syncWindow($connection, $windowStart, $windowEnd);
        }

        $connection->update([
            'last_synced_at' => now(),
            'last_synced_until_date' => $end->toDateString(),
        ]);
    }

    /**
     * Replaces the window instead of upserting: a keyword Search Console drops from a
     * revised day must disappear here too.
     */
    public function syncWindow(GscConnection $connection, CarbonImmutable $start, CarbonImmutable $end): void
    {
        $rows = [];

        foreach ($this->api->searchAnalytics($connection, $start, $end) as $row) {
            [$date, $query, $url] = $row['keys'];

            $rows[] = [
                'date' => $date,
                'keyword' => Str::limit($query, self::NAME_LENGTH),
                'page' => $this->pagePath($url),
                'clicks' => (int) $row['clicks'],
                'impressions' => (int) $row['impressions'],
                'position' => (float) $row['position'],
            ];
        }

        $keywordIds = $this->lookupIds(StatsTrackKeyword::class, $connection->team_id, array_column($rows, 'keyword'));
        $pageIds = $this->lookupIds(StatsPage::class, $connection->team_id, array_column($rows, 'page'));

        $records = [];

        // One key per day, keyword and page: urls that only differ by a trailing slash
        // or a query string normalize to the same page and are merged.
        foreach ($rows as $row) {
            $key = $row['date'].'|'.$keywordIds[$row['keyword']].'|'.$pageIds[$row['page']];
            $record = $records[$key] ?? [
                'team_id' => $connection->team_id,
                'project_id' => $connection->project_id,
                'date' => $row['date'],
                'stats_track_keyword_id' => $keywordIds[$row['keyword']],
                'stats_page_id' => $pageIds[$row['page']],
                'clicks' => 0,
                'impressions' => 0,
                'position_sum' => 0.0,
            ];

            $record['clicks'] += $row['clicks'];
            $record['impressions'] += $row['impressions'];
            $record['position_sum'] += $row['position'] * $row['impressions'];
            $records[$key] = $record;
        }

        $records = array_map(function (array $record): array {
            $record['position'] = round($record['position_sum'] / max($record['impressions'], 1), 2);
            unset($record['position_sum']);

            return $record;
        }, array_values($records));

        DB::transaction(function () use ($connection, $start, $end, $records): void {
            // A disconnect while the window was fetched deletes the connection and its rows,
            // the lock waits for it, so no rows are written back afterwards.
            if (! GscConnection::withoutGlobalScopes()->whereKey($connection->getKey())->lockForUpdate()->exists()) {
                return;
            }

            StatsKeywordDaily::withoutGlobalScopes()
                ->where('project_id', $connection->project_id)
                ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
                ->delete();

            foreach (array_chunk($records, 1000) as $chunk) {
                StatsKeywordDaily::insert($chunk);
            }

            $connection->update(['last_synced_at' => now()]);
        });
    }

    /**
     * @return list<array{0: CarbonImmutable, 1: CarbonImmutable}>
     */
    private function windows(CarbonImmutable $start, CarbonImmutable $end): array
    {
        $windows = [];

        for ($cursor = $start; $cursor->lte($end); $cursor = $cursor->addDays(self::BACKFILL_CHUNK_DAYS)) {
            $windows[] = [$cursor, $cursor->addDays(self::BACKFILL_CHUNK_DAYS - 1)->min($end)];
        }

        return $windows;
    }

    /**
     * Search Console finalizes a day about two days later (Pacific Time).
     */
    public function lastAvailableDate(): CarbonImmutable
    {
        return CarbonImmutable::now('America/Los_Angeles')->subDays(2)->startOfDay();
    }

    /**
     * Same normalization as entry pages, so a keyword's landing page matches the entry
     * page its visitors arrived on. Paths of non-page files (e.g. a ranking PDF) stay as
     * they are, their clicks still count for the keyword.
     */
    private function pagePath(string $url): string
    {
        $path = Str::before(Str::before((string) (parse_url($url, PHP_URL_PATH) ?: '/'), '#'), '?');

        return Str::limit(PagePathHelper::normalize($path) ?? $path, self::NAME_LENGTH);
    }

    /**
     * @param  class-string<Model>  $model
     * @param  list<string>  $names
     * @return array<string, int>
     */
    private function lookupIds(string $model, int $teamId, array $names): array
    {
        $names = array_values(array_unique($names));
        sort($names);

        $ids = [];

        foreach (array_chunk($names, 1000) as $chunk) {
            $model::insertOrIgnore(array_map(fn (string $name): array => ['team_id' => $teamId, 'name' => $name], $chunk));

            $ids += $model::withoutGlobalScopes()
                ->where('team_id', $teamId)
                ->whereIn('name', $chunk)
                ->pluck('id', 'name')
                ->map(fn (mixed $id): int => (int) $id)
                ->all();
        }

        return $ids;
    }
}
