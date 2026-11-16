<?php

use App\DTOs\StatsFilterDto;
use App\Enums\StatsRangeType;
use App\Models\Project;
use App\Models\StatsAggregation;
use App\Models\StatsVisitor;
use App\Models\User;
use App\Observers\StatsObserver;
use App\Services\StatsAggregationsService;
use App\Services\StatsViewIngestService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Carbon\Month;
use Carbon\WeekDay;
use Database\Seeders\TestUserSeeder;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function (): void {
    $this->seed(TestUserSeeder::class);
    $this->user = User::where('email', config('app.test.user')['email'])->firstOrFail();
    $this->project = Project::first();
    $this->team = $this->project->team;
    $this->team->update(['timezone' => 'UTC']);

    DB::table('stats_custom_events')->delete();
    DB::table('stats_payments')->delete();
    DB::table('stats_logins')->delete();
    DB::table('stats_visitors')->delete();
    DB::table('stats_users')->delete();

    auth()->setUser($this->user);
    Context::add('team_id', $this->team->id);
    config(['app.stats_aggregation_enabled' => true]);

    $this->now = '2026-06-14 12:00:00';
    Carbon::setTestNow(Carbon::parse($this->now));

    $this->service = resolve(StatsAggregationsService::class);
    $this->ingest = resolve(StatsViewIngestService::class);
    $this->dayFilter = fn (array $extra = []): StatsFilterDto => new StatsFilterDto(...array_merge([
        'projectId' => $this->project->id,
        'rangeAmount' => 1,
        'rangeType' => StatsRangeType::DAY,
        'rangeStart' => CarbonImmutable::parse($this->now),
        'comparison' => '0',
    ], $extra));
});

afterEach(function (): void {
    StatsObserver::$enabled = true;
    Carbon::setTestNow();
});

function viewVisitor(Project $project, string $hash, string $time, string $browser = 'Chrome'): StatsVisitor
{
    StatsObserver::$enabled = false;

    $visitor = StatsVisitor::factory()->create([
        'team_id' => $project->team_id, 'project_id' => $project->id, 'visitor_hash' => $hash,
        'time' => $time, 'time_utc' => $time,
        'location_country' => null, 'location_region' => null, 'location_city' => null,
        'device_type' => null, 'device_platform' => null, 'device_browser' => $browser,
        'track_referer' => null, 'track_source' => null, 'track_medium' => null,
        'track_campaign' => null, 'track_term' => null, 'track_content' => null, 'page_entry' => null,
    ]);

    StatsObserver::$enabled = true;

    return $visitor;
}

/**
 * @param  array<string, array<string, int>>  $viewsPerHash  hash => [page => views]
 */
function ingestViews(StatsViewIngestService $ingest, Project $project, array $viewsPerHash, ?string $batchId = null): string
{
    $batchId ??= (string) Str::uuid();
    $items = [];

    foreach ($viewsPerHash as $hash => $pages) {
        foreach ($pages as $page => $views) {
            $items[] = ['visitor_hash' => $hash, 'page' => $page, 'views' => $views, 'last_viewed_at' => now()->getTimestamp()];
        }
    }

    $ingest->ingest($project, $batchId, $items);

    return $batchId;
}

function viewKeys(array $row): array
{
    return ['pd_visitor' => $row['pd_visitor'], 'pd_view' => $row['pd_view'], 'pd_viewer' => $row['pd_viewer'], 'pd_bounce' => $row['pd_bounce']];
}

function chromeId(): int
{
    return (int) DB::table('stats_device_browsers')->where('name', 'Chrome')->value('id');
}

it('computes views, viewers and bounces per bucket from the raw tables', function (): void {
    viewVisitor($this->project, 'a', $this->now);
    viewVisitor($this->project, 'b', $this->now);
    viewVisitor($this->project, 'no-views', $this->now);
    ingestViews($this->ingest, $this->project, ['a' => ['/' => 1], 'b' => ['/' => 2, '/docs' => 1]]);
    StatsAggregation::query()->delete();

    $row = $this->service->getAggregatedData(($this->dayFilter)())['2026-06-14'];

    expect(viewKeys($row))->toBe(['pd_visitor' => 3, 'pd_view' => 4, 'pd_viewer' => 2, 'pd_bounce' => 1]);
});

it('increments cached buckets to the same values a recomputation yields, across bounce transitions', function (): void {
    viewVisitor($this->project, 'a', $this->now);
    viewVisitor($this->project, 'b', $this->now, 'Safari');
    $unfiltered = ($this->dayFilter)();
    $chrome = ($this->dayFilter)(['deviceBrowser' => chromeId()]);
    $this->service->getAggregatedData($unfiltered);
    $this->service->getAggregatedData($chrome);

    ingestViews($this->ingest, $this->project, ['a' => ['/' => 1], 'b' => ['/' => 1]]);
    ingestViews($this->ingest, $this->project, ['a' => ['/docs' => 1]]);
    $incrementedUnfiltered = viewKeys($this->service->getAggregatedData($unfiltered)['2026-06-14']);
    $incrementedChrome = viewKeys($this->service->getAggregatedData($chrome)['2026-06-14']);

    expect($incrementedUnfiltered)->toBe(['pd_visitor' => 2, 'pd_view' => 3, 'pd_viewer' => 2, 'pd_bounce' => 1])
        ->and($incrementedChrome)->toBe(['pd_visitor' => 1, 'pd_view' => 2, 'pd_viewer' => 1, 'pd_bounce' => 0]);

    StatsAggregation::query()->delete();

    expect(viewKeys($this->service->getAggregatedData($unfiltered)['2026-06-14']))->toBe($incrementedUnfiltered)
        ->and(viewKeys($this->service->getAggregatedData($chrome)['2026-06-14']))->toBe($incrementedChrome);
});

it('increments a closed bucket in place instead of dropping it', function (): void {
    viewVisitor($this->project, 'yesterday', '2026-06-13 09:00:00');
    $filter = ($this->dayFilter)(['rangeAmount' => 2]);
    $this->service->getAggregatedData($filter);
    $cachedId = StatsAggregation::where('filter_hash', $filter->makeHash())->where('time', '2026-06-13')->value('id');

    ingestViews($this->ingest, $this->project, ['yesterday' => ['/' => 2]]);

    $row = StatsAggregation::find($cachedId);
    expect($row)->not->toBeNull()
        ->and(viewKeys($row->data))->toBe(['pd_visitor' => 1, 'pd_view' => 2, 'pd_viewer' => 1, 'pd_bounce' => 0]);
});

it('recomputes a cached bucket that predates the view keys', function (): void {
    viewVisitor($this->project, 'a', $this->now);
    $filter = ($this->dayFilter)();
    $this->service->getAggregatedData($filter);
    $row = StatsAggregation::where('filter_hash', $filter->makeHash())->where('time', '2026-06-14')->first();
    $row->update(['data' => Arr::except($row->data, ['pd_view', 'pd_viewer', 'pd_bounce'])]);

    ingestViews($this->ingest, $this->project, ['a' => ['/' => 3]]);

    expect(viewKeys($this->service->getAggregatedData($filter)['2026-06-14']))
        ->toBe(['pd_visitor' => 1, 'pd_view' => 3, 'pd_viewer' => 1, 'pd_bounce' => 0]);
});

it('drops the cached buckets of a batch whose increments were lost before its retry', function (): void {
    viewVisitor($this->project, 'a', $this->now);
    $filter = ($this->dayFilter)();
    $batchId = ingestViews($this->ingest, $this->project, ['a' => ['/' => 1]]);
    $this->service->getAggregatedData($filter);
    DB::table('stats_view_batches')->where('batch_id', $batchId)->update(['cache_applied_at' => null]);

    ingestViews($this->ingest, $this->project, ['a' => ['/' => 1]], $batchId);

    expect(StatsAggregation::where('filter_hash', $filter->makeHash())->where('time', '2026-06-14')->exists())->toBeFalse()
        ->and(DB::table('stats_view_batches')->where('batch_id', $batchId)->value('cache_applied_at'))->not->toBeNull()
        ->and(DB::table('stats_visitor_views')->sum('views'))->toBe(1);
});

it('the nightly refresh drops yesterday buckets of projects with views and keeps the rest', function (): void {
    viewVisitor($this->project, 'a', '2026-06-13 09:00:00');
    ingestViews($this->ingest, $this->project, ['a' => ['/' => 1]]);
    $filter = ($this->dayFilter)(['rangeAmount' => 3]);
    $this->service->getAggregatedData($filter);

    $this->artisan('app:refresh-page-view-aggregations')->assertSuccessful();

    $cachedDays = StatsAggregation::where('filter_hash', $filter->makeHash())->pluck('time')
        ->map(fn (DateTimeInterface|WeekDay|Month|string|int|float|null $time): string => CarbonImmutable::parse($time)->toDateString())->sort()->values()->all();
    expect($cachedDays)->toBe(['2026-06-12', '2026-06-14']);
});

it('adds view columns to the stats groups only once the scope has recent views', function (): void {
    viewVisitor($this->project, 'a', $this->now);
    viewVisitor($this->project, 'b', $this->now);
    viewVisitor($this->project, 'c', $this->now, 'Safari');
    $filter = ($this->dayFilter)();

    $beforeViews = $this->service->getAggregatedGroupedData($filter, 'device_browser', 'visitors', 10, withViews: true);
    expect($beforeViews->first())->not->toHaveKey('views');

    ingestViews($this->ingest, $this->project, ['a' => ['/' => 1], 'b' => ['/' => 3], 'c' => ['/' => 1]]);

    $chrome = $this->service->getAggregatedGroupedData($filter, 'device_browser', 'views', 10, withViews: true)->firstWhere('name', 'Chrome');
    expect(Arr::only($chrome, ['visitors', 'views', 'viewers', 'bounces', 'vpv', 'bounce_rate']))
        ->toBe(['visitors' => 2, 'views' => 4, 'viewers' => 2, 'bounces' => 1, 'vpv' => 2.0, 'bounce_rate' => 50.0]);
});

it('stops showing view columns once the last view is older than the visibility window', function (): void {
    viewVisitor($this->project, 'a', $this->now);
    ingestViews($this->ingest, $this->project, ['a' => ['/' => 1]]);
    $this->project->forceFill(['page_views_seen_at' => now()->subDays(Project::PAGE_VIEWS_VISIBLE_DAYS + 1)->toDateString()])->save();

    $grouped = $this->service->getAggregatedGroupedData(($this->dayFilter)(), 'device_browser', 'visitors', 10, withViews: true);

    expect($grouped->first())->not->toHaveKey('views');
});

it('sums the views of the visitors that triggered each custom event', function (): void {
    $visitor = viewVisitor($this->project, 'a', $this->now);
    viewVisitor($this->project, 'b', $this->now);
    ingestViews($this->ingest, $this->project, ['a' => ['/' => 3], 'b' => ['/' => 5]]);
    $eventNameId = DB::table('stats_custom_event_names')->insertGetId([
        'team_id' => $this->team->id, 'project_id' => $this->project->id, 'name' => 'Signup click',
        'created_at' => $this->now, 'updated_at' => $this->now,
    ]);
    foreach ([1, 2] as $ignored) {
        DB::table('stats_custom_events')->insert([
            'id' => Str::uuid()->toString(), 'team_id' => $this->team->id, 'project_id' => $this->project->id,
            'stats_custom_event_name_id' => $eventNameId, 'stats_visitor_id' => $visitor->id,
            'time' => $this->now, 'time_utc' => $this->now,
        ]);
    }

    $grouped = $this->service->getAggregatedGroupedData(($this->dayFilter)(), 'custom_event_name', 'views', 10, withViews: true);

    expect($grouped->firstWhere('name', 'Signup click')['views'])->toBe(3);
});

it('lists top pages with views and visitors, narrowed by the active filters', function (): void {
    viewVisitor($this->project, 'a', $this->now);
    viewVisitor($this->project, 'b', $this->now);
    viewVisitor($this->project, 'c', $this->now, 'Safari');
    ingestViews($this->ingest, $this->project, [
        'a' => ['/' => 1, '/pricing' => 4],
        'b' => ['/pricing' => 1],
        'c' => ['/' => 7],
    ]);

    $all = $this->service->getTopPagesData(($this->dayFilter)());
    $chromeOnly = $this->service->getTopPagesData(($this->dayFilter)(['deviceBrowser' => chromeId()]));

    expect($all->map(fn (array $row): array => Arr::only($row, ['name', 'views', 'visitors']))->all())->toBe([
        ['name' => '/', 'views' => 8, 'visitors' => 2],
        ['name' => 'pricing/', 'views' => 5, 'visitors' => 2],
    ])->and($chromeOnly->pluck('views', 'name')->all())->toBe(['pricing/' => 5, '/' => 1]);
});

it('merges top pages of all projects of the team in the all projects view', function (): void {
    $otherProject = Project::factory()->create(['team_id' => $this->team->id]);
    viewVisitor($this->project, 'a', $this->now);
    viewVisitor($otherProject, 'b', $this->now);
    ingestViews($this->ingest, $this->project, ['a' => ['/pricing' => 2]]);
    ingestViews($this->ingest, $otherProject, ['b' => ['/pricing' => 3]]);

    $pages = $this->service->getTopPagesData(($this->dayFilter)(['projectId' => StatsFilterDto::TRACK_ALL]));

    expect($pages->pluck('views', 'name')->all())->toBe(['pricing/' => 5]);
});
