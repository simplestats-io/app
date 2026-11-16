<?php

namespace App\Services;

use App\DTOs\StatsFilterDto;
use App\Jobs\ResolvePendingStatsViews;
use App\Models\Project;
use App\Models\StatsPage;
use App\Models\StatsViewBatch;
use App\Models\StatsViewPending;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Writes page view batches through the query builder, past Eloquent: a StatsVisitor
 * update would fire the StatsObserver and wipe the aggregation cache for every batch.
 *
 * @phpstan-type ViewItem array{visitor_hash: string, page: string, views: int, last_viewed_at: int}
 * @phpstan-type ViewContribution array{attrs: array<string, mixed>, time: string, increments: array<string, int>}
 */
class StatsViewIngestService
{
    /** Hard ceiling against broken or manipulated clients, the client itself caps lower. */
    public const int MAX_VIEWS_PER_VISITOR_DAY = 500;

    /** Delays before each further attempt to match views whose visitor was unknown. */
    public const array PENDING_RETRY_DELAYS_MINUTES = [2, 15, 90];

    public function __construct(
        private readonly StatsIncrementService $increments,
    ) {}

    public function isKnownBatch(int $projectId, string $batchId): bool
    {
        return StatsViewBatch::forBatch($projectId, $batchId)->exists();
    }

    /**
     * @param  list<ViewItem>  $items
     */
    public function ingest(Project $project, string $batchId, array $items, ?string $mode = null): void
    {
        $visitors = $this->resolveVisitors($project, $items);
        [$matched, $unmatched] = $this->partitionByVisitor($items, $visitors);
        $pageIds = $this->resolvePageIds($project, $matched);

        // null marks a batch that was already stored.
        $contributions = DB::transaction(function () use ($project, $batchId, $items, $mode, $matched, $unmatched, $visitors, $pageIds): ?array {
            $isNew = StatsViewBatch::insertOrIgnore([
                'project_id' => $project->id,
                'batch_id' => $batchId,
                'views' => $this->sumViews($items),
                'matched_views' => $this->sumViews($matched),
                'mode' => $mode,
            ]) === 1;

            if (! $isNew) {
                return null;
            }

            $contributions = $this->storeViews($matched, $visitors, $pageIds);
            $this->storePending($project, $batchId, $unmatched);

            return $contributions;
        });

        if (is_null($contributions)) {
            $this->recoverLostCacheIncrements($project, $batchId, $matched, $visitors);

            return;
        }

        // Outside the transaction on purpose: row locks on hot cache rows held for the whole
        // batch would block the other ingest jobs, and could deadlock with their deletes.
        $this->increments->handleViews($project->team_id, $project->id, $contributions);
        $this->markCacheApplied($project, $batchId);
        $this->markProjectAsViewed($project, $matched, $visitors);

        if ($unmatched !== []) {
            dispatch(new ResolvePendingStatsViews($project->id, $batchId, 0))
                ->delay(now()->addMinutes(self::PENDING_RETRY_DELAYS_MINUTES[0]));
        }
    }

    public function resolvePending(Project $project, string $batchId, int $attempt): void
    {
        $contributions = [];
        $matched = [];
        $visitors = collect();

        $retryLater = DB::transaction(function () use ($project, $batchId, $attempt, &$contributions, &$matched, &$visitors): bool {
            $items = $this->claimPending($project, $batchId);

            if ($items === []) {
                return false;
            }

            $visitors = $this->resolveVisitors($project, $items);
            [$matched, $unmatched] = $this->partitionByVisitor($items, $visitors);

            $contributions = $this->storeViews($matched, $visitors, $this->resolvePageIds($project, $matched));

            if ($matched !== []) {
                StatsViewBatch::forBatch($project->id, $batchId)->increment('matched_views', $this->sumViews($matched));
            }

            $hasNextAttempt = isset(self::PENDING_RETRY_DELAYS_MINUTES[$attempt + 1]);

            if ($hasNextAttempt) {
                $this->storePending($project, $batchId, $unmatched);
            }

            return $hasNextAttempt && $unmatched !== [];
        });

        $this->increments->handleViews($project->team_id, $project->id, $contributions);
        $this->markProjectAsViewed($project, $matched, $visitors);

        if ($retryLater) {
            dispatch(new ResolvePendingStatsViews($project->id, $batchId, $attempt + 1))
                ->delay(now()->addMinutes(self::PENDING_RETRY_DELAYS_MINUTES[$attempt + 1]));
        }
    }

    /**
     * Claiming by delete keeps a doubled or retried resolve job from counting a view twice.
     *
     * @return list<ViewItem>
     */
    private function claimPending(Project $project, string $batchId): array
    {
        $rows = DB::select(
            'DELETE FROM stats_views_pending WHERE project_id = ? AND batch_id = ? RETURNING visitor_hash, page, views, last_viewed_at',
            [$project->id, $batchId],
        );

        $items = [];

        foreach ($rows as $row) {
            $items[] = [
                'visitor_hash' => (string) $row->visitor_hash,
                'page' => (string) $row->page,
                'views' => (int) $row->views,
                'last_viewed_at' => CarbonImmutable::parse($row->last_viewed_at, 'UTC')->getTimestamp(),
            ];
        }

        return $items;
    }

    /**
     * @param  list<ViewItem>  $items
     * @return Collection<string, stdClass>
     */
    private function resolveVisitors(Project $project, array $items): Collection
    {
        $hashes = array_values(array_unique(array_column($items, 'visitor_hash')));

        if ($hashes === []) {
            return collect();
        }

        // Query builder on purpose: the naive time column must reach the cache step uncast,
        // an Eloquent datetime cast would re-read it in whatever timezone is active.
        return DB::table('stats_visitors')
            ->where('team_id', $project->team_id)
            ->where('project_id', $project->id)
            ->whereIn('visitor_hash', $hashes)
            ->get(['id', 'visitor_hash', 'time', ...array_values(StatsFilterDto::FILTER_DIMENSION_COLUMNS)])
            ->keyBy('visitor_hash');
    }

    /**
     * @param  list<ViewItem>  $items
     * @param  Collection<string, stdClass>  $visitors
     * @return array{list<ViewItem>, list<ViewItem>}
     */
    private function partitionByVisitor(array $items, Collection $visitors): array
    {
        $matched = [];
        $unmatched = [];

        foreach ($items as $item) {
            if ($visitors->has($item['visitor_hash'])) {
                $matched[] = $item;
            } else {
                $unmatched[] = $item;
            }
        }

        return [$matched, $unmatched];
    }

    /**
     * Created outside the batch transaction: a page row is harmless if the batch rolls back,
     * while holding its unique-index lock until commit would serialize the team's batches.
     *
     * @param  list<ViewItem>  $items
     * @return array<string, int>
     */
    private function resolvePageIds(Project $project, array $items): array
    {
        $names = array_values(array_unique(array_column($items, 'page')));
        sort($names);

        if ($names === []) {
            return [];
        }

        StatsPage::insertOrIgnore(
            array_map(fn (string $name): array => ['team_id' => $project->team_id, 'name' => $name], $names)
        );

        return StatsPage::where('team_id', $project->team_id)
            ->whereIn('name', $names)
            ->pluck('id', 'name')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
    }

    /**
     * team_id, project_id and time are copied from stats_visitors inside the statement,
     * so the copy is exact and no timezone handling happens in PHP. Rows are written in
     * key order to keep concurrent batches of the same team from deadlocking.
     *
     * Returns the cache deltas per visitor. The old count is derived from the new one,
     * so no extra read is needed: an inserted row had 0 views, an updated one at least 1.
     *
     * @param  list<ViewItem>  $items
     * @param  Collection<string, stdClass>  $visitors
     * @param  array<string, int>  $pageIds
     * @return list<ViewContribution>
     */
    private function storeViews(array $items, Collection $visitors, array $pageIds): array
    {
        if ($items === []) {
            return [];
        }

        $perVisitor = [];
        $perPage = [];

        foreach ($items as $item) {
            $visitorId = (int) $visitors[$item['visitor_hash']]->id;

            $perVisitor[$visitorId] = [
                $visitorId,
                ($perVisitor[$visitorId][1] ?? 0) + $item['views'],
                max($perVisitor[$visitorId][2] ?? 0, $item['last_viewed_at']),
            ];
            $perPage[] = [$visitorId, $pageIds[$item['page']], $item['views']];
        }

        ksort($perVisitor);
        usort($perPage, fn (array $a, array $b): int => [$a[0], $a[1]] <=> [$b[0], $b[1]]);

        $visitorBindings = [];

        foreach ($perVisitor as [$visitorId, $views, $lastViewedAt]) {
            $visitorBindings[] = $visitorId;
            $visitorBindings[] = $views;
            $visitorBindings[] = $this->formatUtc($lastViewedAt);
        }

        $cap = self::MAX_VIEWS_PER_VISITOR_DAY;

        $stored = DB::select(
            'INSERT INTO stats_visitor_views (stats_visitor_id, team_id, project_id, time, views, last_viewed_at)
            SELECT sv.id, sv.team_id, sv.project_id, sv.time, LEAST(d.views, '.$cap.'), d.last_viewed_at
            FROM (VALUES '.$this->valuesPlaceholders(count($perVisitor), '(?::bigint, ?::int, ?::timestamp)').') AS d (stats_visitor_id, views, last_viewed_at)
            JOIN stats_visitors sv ON sv.id = d.stats_visitor_id
            ORDER BY sv.id
            ON CONFLICT (stats_visitor_id) DO UPDATE
            SET views = LEAST(stats_visitor_views.views + EXCLUDED.views, '.$cap.'),
                last_viewed_at = GREATEST(stats_visitor_views.last_viewed_at, EXCLUDED.last_viewed_at)
            RETURNING stats_visitor_id, views, (xmax = 0) AS inserted',
            $visitorBindings,
        );

        DB::statement(
            'INSERT INTO stats_visitor_pages (stats_visitor_id, stats_page_id, team_id, project_id, time, views)
            SELECT sv.id, d.stats_page_id, sv.team_id, sv.project_id, sv.time, LEAST(d.views, '.$cap.')
            FROM (VALUES '.$this->valuesPlaceholders(count($perPage), '(?::bigint, ?::bigint, ?::int)').') AS d (stats_visitor_id, stats_page_id, views)
            JOIN stats_visitors sv ON sv.id = d.stats_visitor_id
            ORDER BY sv.id, d.stats_page_id
            ON CONFLICT (stats_visitor_id, stats_page_id) DO UPDATE
            SET views = LEAST(stats_visitor_pages.views + EXCLUDED.views, '.$cap.')',
            array_merge(...$perPage),
        );

        $visitorsById = $visitors->keyBy('id');
        $contributions = [];

        foreach ($stored as $row) {
            $newViews = (int) $row->views;
            $oldViews = $row->inserted ? 0 : max(1, $newViews - $perVisitor[(int) $row->stats_visitor_id][1]);

            $increments = array_filter([
                'pd_view' => $newViews - $oldViews,
                'pd_viewer' => $row->inserted ? 1 : 0,
                'pd_bounce' => ($newViews === 1 ? 1 : 0) - ($oldViews === 1 ? 1 : 0),
            ]);

            if ($increments === []) {
                continue;
            }

            $visitor = $visitorsById[(int) $row->stats_visitor_id];

            $contributions[] = [
                'attrs' => Arr::only((array) $visitor, StatsFilterDto::FILTER_DIMENSION_COLUMNS),
                'time' => (string) $visitor->time,
                'increments' => $increments,
            ];
        }

        return $contributions;
    }

    /**
     * @param  list<ViewItem>  $matched
     * @param  Collection<string, stdClass>  $visitors
     */
    private function recoverLostCacheIncrements(Project $project, string $batchId, array $matched, Collection $visitors): void
    {
        $lost = StatsViewBatch::forBatch($project->id, $batchId)->whereNull('cache_applied_at')->exists();

        if (! $lost) {
            return;
        }

        $times = array_values(array_unique(array_map(fn (array $item): string => (string) $visitors[$item['visitor_hash']]->time, $matched)));

        $this->increments->invalidateViewBuckets($project->team_id, $project->id, $times);
        $this->markCacheApplied($project, $batchId);
    }

    private function markCacheApplied(Project $project, string $batchId): void
    {
        StatsViewBatch::forBatch($project->id, $batchId)->update(['cache_applied_at' => now()]);
    }

    /**
     * @param  list<ViewItem>  $items
     */
    private function storePending(Project $project, string $batchId, array $items): void
    {
        if ($items === []) {
            return;
        }

        StatsViewPending::insert(array_map(fn (array $item): array => [
            'project_id' => $project->id,
            'batch_id' => $batchId,
            'visitor_hash' => $item['visitor_hash'],
            'page' => $item['page'],
            'views' => $item['views'],
            'last_viewed_at' => $this->formatUtc($item['last_viewed_at']),
        ], $items));
    }

    /**
     * Uses the visitors' own (team-local) times, the same clock the dashboard ranges use.
     * The WHERE clause keeps this to at most one write per project and day.
     *
     * @param  list<ViewItem>  $items
     * @param  Collection<string, stdClass>  $visitors
     */
    private function markProjectAsViewed(Project $project, array $items, Collection $visitors): void
    {
        if ($items === []) {
            return;
        }

        $times = array_map(fn (array $item): string => (string) $visitors[$item['visitor_hash']]->time, $items);
        $firstTime = min($times);
        $lastDay = CarbonImmutable::parse(max($times))->toDateString();

        DB::update(
            'UPDATE projects
            SET page_views_since = LEAST(COALESCE(page_views_since, ?::timestamp), ?::timestamp),
                page_views_seen_at = GREATEST(COALESCE(page_views_seen_at, ?::date), ?::date)
            WHERE id = ?
              AND (page_views_since IS NULL OR page_views_since > ? OR page_views_seen_at IS NULL OR page_views_seen_at < ?)',
            [$firstTime, $firstTime, $lastDay, $lastDay, $project->id, $firstTime, $lastDay],
        );
    }

    /**
     * @param  list<ViewItem>  $items
     */
    private function sumViews(array $items): int
    {
        return array_sum(array_column($items, 'views'));
    }

    private function valuesPlaceholders(int $rows, string $row): string
    {
        return implode(', ', array_fill(0, $rows, $row));
    }

    private function formatUtc(int $timestamp): string
    {
        return CarbonImmutable::createFromTimestampUTC($timestamp)->format('Y-m-d H:i:s');
    }
}
