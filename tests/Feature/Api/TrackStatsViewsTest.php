<?php

use App\Helpers\AppHelper;
use App\Jobs\ResolvePendingStatsViews;
use App\Jobs\TrackStatsViews;
use App\Models\Project;
use App\Models\StatsViewBatch;
use App\Models\StatsViewPending;
use App\Models\StatsVisitor;
use App\Services\StatsViewIngestService;
use Database\Seeders\TestUserSeeder;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

beforeEach(function (): void {
    $this->seed(TestUserSeeder::class);
    $this->project = Project::first();
    $this->token = $this->project->createToken('test-token')->plainTextToken;

    // TestUserSeeder leaves the test user on the web guard; forget it so the
    // Bearer token (the project) wins over Sanctum's stateful session check.
    auth()->forgetGuards();
});

function createViewsVisitor(Project $project, string $hash, string $time = '2026-11-15 10:00:00'): StatsVisitor
{
    auth()->setUser($project);
    AppHelper::setTeamContextFromAuth($project);
    Context::add('project_id', $project->id);

    $visitor = StatsVisitor::factory()->create([
        'team_id' => $project->team_id,
        'project_id' => $project->id,
        'visitor_hash' => $hash,
        'time' => $time,
        'time_utc' => $time,
    ]);

    auth()->forgetGuards();
    Context::forget('project_id');

    return $visitor;
}

function viewsPayload(array $views, ?string $batchId = null): array
{
    return [
        'batch_id' => $batchId ?? (string) Str::uuid(),
        'views' => $views,
        'meta' => ['mode' => 'worker'],
    ];
}

function viewItem(string $hash, string $page = '/', int $count = 1, ?int $time = null): array
{
    return ['h' => $hash, 't' => $time ?? now()->startOfMinute()->getTimestamp(), 'n' => $count, 'p' => $page];
}

function pageViewsOf(StatsVisitor $visitor): array
{
    return DB::table('stats_visitor_pages')
        ->join('stats_pages', 'stats_pages.id', '=', 'stats_visitor_pages.stats_page_id')
        ->where('stats_visitor_id', $visitor->id)
        ->pluck('stats_visitor_pages.views', 'stats_pages.name')
        ->map(fn (mixed $views): int => (int) $views)
        ->sortKeys()
        ->all();
}

function totalViewsOf(StatsVisitor $visitor): ?int
{
    $views = DB::table('stats_visitor_views')->where('stats_visitor_id', $visitor->id)->value('views');

    return is_null($views) ? null : (int) $views;
}

test('a valid batch is stored per visitor and per page', function (): void {
    $visitor = createViewsVisitor($this->project, 'hash-a', '2026-11-15 10:00:00');
    $payload = viewsPayload([viewItem('hash-a', '/'), viewItem('hash-a', '/pricing', 2), viewItem('hash-a', '/', 1)]);

    $this->postJson('/api/v1/stats-views', $payload, apiHeaders($this->token))
        ->assertOk()
        ->assertJson(['batch_id' => $payload['batch_id'], 'accepted' => 2, 'rejected' => 0, 'duplicate' => false]);

    expect(totalViewsOf($visitor))->toBe(4)
        ->and(pageViewsOf($visitor))->toBe(['/' => 2, '/pricing' => 2]);

    $this->assertDatabaseHas('stats_visitor_views', [
        'stats_visitor_id' => $visitor->id,
        'team_id' => $this->project->team_id,
        'project_id' => $this->project->id,
        'time' => '2026-11-15 10:00:00',
    ]);
    $this->assertDatabaseHas('stats_view_batches', [
        'project_id' => $this->project->id,
        'batch_id' => $payload['batch_id'],
        'views' => 4,
        'matched_views' => 4,
        'mode' => 'worker',
    ]);
    $this->assertDatabaseHas('projects', [
        'id' => $this->project->id,
        'page_views_since' => '2026-11-15 10:00:00',
        'page_views_seen_at' => '2026-11-15',
    ]);
});

test('a later batch adds to the existing counts', function (): void {
    $visitor = createViewsVisitor($this->project, 'hash-a');

    $this->postJson('/api/v1/stats-views', viewsPayload([viewItem('hash-a', '/', 2)]), apiHeaders($this->token))->assertOk();
    $this->postJson('/api/v1/stats-views', viewsPayload([viewItem('hash-a', '/', 1), viewItem('hash-a', '/docs')]), apiHeaders($this->token))->assertOk();

    expect(totalViewsOf($visitor))->toBe(4)
        ->and(pageViewsOf($visitor))->toBe(['/' => 3, '/docs' => 1]);
});

test('a resent batch is reported as duplicate and not counted again', function (): void {
    $visitor = createViewsVisitor($this->project, 'hash-a');
    $payload = viewsPayload([viewItem('hash-a', '/', 3)]);

    $this->postJson('/api/v1/stats-views', $payload, apiHeaders($this->token))->assertOk();
    $this->postJson('/api/v1/stats-views', $payload, apiHeaders($this->token))
        ->assertOk()
        ->assertJson(['duplicate' => true]);

    expect(totalViewsOf($visitor))->toBe(3);
});

test('a job that runs twice for the same batch counts its views once', function (): void {
    $visitor = createViewsVisitor($this->project, 'hash-a');
    $items = [['visitor_hash' => 'hash-a', 'page' => '/', 'views' => 2, 'last_viewed_at' => now()->getTimestamp()]];
    $batchId = (string) Str::uuid();

    $ingest = resolve(StatsViewIngestService::class);
    $ingest->ingest($this->project, $batchId, $items);
    $ingest->ingest($this->project, $batchId, $items);

    expect(totalViewsOf($visitor))->toBe(2)
        ->and(pageViewsOf($visitor))->toBe(['/' => 2]);
});

test('views per visitor-day are capped by the backend', function (): void {
    $visitor = createViewsVisitor($this->project, 'hash-a');

    $this->postJson('/api/v1/stats-views', viewsPayload([viewItem('hash-a', '/', 1000)]), apiHeaders($this->token))->assertOk();
    $this->postJson('/api/v1/stats-views', viewsPayload([viewItem('hash-a', '/', 1)]), apiHeaders($this->token))->assertOk();

    expect(totalViewsOf($visitor))->toBe(StatsViewIngestService::MAX_VIEWS_PER_VISITOR_DAY)
        ->and(pageViewsOf($visitor))->toBe(['/' => StatsViewIngestService::MAX_VIEWS_PER_VISITOR_DAY]);
});

test('invalid items are skipped individually while valid ones are stored', function (): void {
    $visitor = createViewsVisitor($this->project, 'hash-a');
    $payload = viewsPayload([
        viewItem('hash-a', '/valid'),
        ['h' => 'hash-a', 't' => now()->getTimestamp(), 'n' => 0, 'p' => '/zero'],
        ['h' => 'hash-a', 't' => 'yesterday', 'n' => 1, 'p' => '/string-time'],
        ['h' => 'hash-a', 't' => now()->getTimestamp(), 'n' => 1],
        ['h' => 'hash-a', 't' => now()->getTimestamp(), 'n' => 1, 'p' => 'no-leading-slash'],
        viewItem('hash-a', '/logo.png'),
        'not-an-item',
    ]);

    $this->postJson('/api/v1/stats-views', $payload, apiHeaders($this->token))
        ->assertOk()
        ->assertJson(['accepted' => 1, 'rejected' => 6]);

    expect(pageViewsOf($visitor))->toBe(['/valid' => 1]);
});

test('paths are normalized like entry pages, without changing their case', function (): void {
    $visitor = createViewsVisitor($this->project, 'hash-a');
    $payload = viewsPayload([
        viewItem('hash-a', '/Pricing/'),
        viewItem('hash-a', '/Pricing?plan=pro#faq'),
        viewItem('hash-a', '/pricing'),
    ]);

    $this->postJson('/api/v1/stats-views', $payload, apiHeaders($this->token))->assertOk();

    expect(pageViewsOf($visitor))->toBe(['/Pricing' => 2, '/pricing' => 1]);
});

test('an invalid envelope is rejected with 422', function (array $payload, array $errors): void {
    $this->postJson('/api/v1/stats-views', $payload, apiHeaders($this->token))
        ->assertUnprocessable()
        ->assertJsonValidationErrors($errors);
})->with([
    'empty payload' => [[], ['batch_id', 'views']],
    'batch id is no uuid' => [['batch_id' => 'abc', 'views' => [['h' => 'x']]], ['batch_id']],
    'more than 1000 views' => [['batch_id' => '9d6f1c1e-7c40-4f0a-b1a2-3e5d8a6b7c90', 'views' => array_fill(0, 1001, ['h' => 'x'])], ['views']],
    'unknown mode' => [['batch_id' => '9d6f1c1e-7c40-4f0a-b1a2-3e5d8a6b7c90', 'views' => [['h' => 'x']], 'meta' => ['mode' => 'redis']], ['meta.mode']],
]);

test('a request without a valid token returns 401', function (): void {
    $this->postJson('/api/v1/stats-views', viewsPayload([viewItem('hash-a')]), apiHeaders('invalid-token'))
        ->assertUnauthorized();
});

test('page views use their own rate limit bucket, separate from the other ingest routes', function (): void {
    RateLimiter::for('api', fn () => Limit::perMinute(1)->by('api-test'));
    RateLimiter::for('page-views', fn () => Limit::perMinute(1)->by('page-views-test'));
    createViewsVisitor($this->project, 'hash-a');

    $this->postJson('/api/v1/stats-views', viewsPayload([viewItem('hash-a')]), apiHeaders($this->token))->assertOk();
    $this->postJson('/api/v1/stats-views', viewsPayload([viewItem('hash-a')]), apiHeaders($this->token))->assertTooManyRequests();

    $this->postJson('/api/v1/stats-visitor', [
        'time' => now()->format('Y-m-d H:i:s P'),
        'ip' => '8.8.8.8',
        'user_agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36',
    ], apiHeaders($this->token))->assertOk();
});

test('reaching the monthly event limit returns a terminal 200 with a pause and stores nothing', function (): void {
    $this->skipUnlessSaas();

    Bus::fake();
    $team = $this->project->team;
    $team->update(['events_count_monthly' => $team->owner->sparkPlan()->options['Events']]);

    $this->postJson('/api/v1/stats-views', viewsPayload([viewItem('hash-a')]), apiHeaders($this->token))
        ->assertOk()
        ->assertExactJson(['message' => 'Monthly event limit reached.', 'pause' => 3600]);

    Bus::assertNotDispatched(TrackStatsViews::class);
});

test('views of an unknown visitor wait in pending and a resolve attempt is scheduled', function (): void {
    Queue::fake([ResolvePendingStatsViews::class]);
    $payload = viewsPayload([viewItem('hash-late', '/', 2)]);

    $this->postJson('/api/v1/stats-views', $payload, apiHeaders($this->token))->assertOk();

    $this->assertDatabaseHas('stats_views_pending', [
        'project_id' => $this->project->id,
        'batch_id' => $payload['batch_id'],
        'visitor_hash' => 'hash-late',
        'page' => '/',
        'views' => 2,
    ]);
    $this->assertDatabaseHas('stats_view_batches', ['batch_id' => $payload['batch_id'], 'views' => 2, 'matched_views' => 0]);
    Queue::assertPushed(ResolvePendingStatsViews::class, fn (ResolvePendingStatsViews $job): bool => $job->batchId === $payload['batch_id'] && $job->attempt === 0);
});

test('pending views are counted once their visitor arrives', function (): void {
    Queue::fake([ResolvePendingStatsViews::class]);
    $payload = viewsPayload([viewItem('hash-late', '/docs', 2)]);
    $this->postJson('/api/v1/stats-views', $payload, apiHeaders($this->token))->assertOk();
    $visitor = createViewsVisitor($this->project, 'hash-late');

    (new ResolvePendingStatsViews($this->project->id, $payload['batch_id'], 0))->handle(resolve(StatsViewIngestService::class));

    expect(totalViewsOf($visitor))->toBe(2)
        ->and(pageViewsOf($visitor))->toBe(['/docs' => 2]);
    $this->assertDatabaseMissing('stats_views_pending', ['batch_id' => $payload['batch_id']]);
    $this->assertDatabaseHas('stats_view_batches', ['batch_id' => $payload['batch_id'], 'matched_views' => 2]);
});

test('pending views are retried and dropped after the last attempt', function (): void {
    Queue::fake([ResolvePendingStatsViews::class]);
    $payload = viewsPayload([viewItem('hash-never')]);
    $this->postJson('/api/v1/stats-views', $payload, apiHeaders($this->token))->assertOk();
    $ingest = resolve(StatsViewIngestService::class);

    (new ResolvePendingStatsViews($this->project->id, $payload['batch_id'], 0))->handle($ingest);

    $this->assertDatabaseHas('stats_views_pending', ['batch_id' => $payload['batch_id']]);
    Queue::assertPushed(ResolvePendingStatsViews::class, fn (ResolvePendingStatsViews $job): bool => $job->attempt === 1);

    (new ResolvePendingStatsViews($this->project->id, $payload['batch_id'], count(StatsViewIngestService::PENDING_RETRY_DELAYS_MINUTES) - 1))->handle($ingest);

    $this->assertDatabaseMissing('stats_views_pending', ['batch_id' => $payload['batch_id']]);
});

test('a visitor hash of another project is never matched', function (): void {
    Queue::fake([ResolvePendingStatsViews::class]);
    $otherProject = Project::factory()->create(['team_id' => $this->project->team_id]);
    $foreignVisitor = createViewsVisitor($otherProject, 'hash-shared');

    $this->postJson('/api/v1/stats-views', viewsPayload([viewItem('hash-shared')]), apiHeaders($this->token))->assertOk();

    expect(totalViewsOf($foreignVisitor))->toBeNull();
    $this->assertDatabaseHas('stats_views_pending', ['project_id' => $this->project->id, 'visitor_hash' => 'hash-shared']);
});

test('pruning deletes expired batch markers and stale pending views only', function (): void {
    StatsViewBatch::insert([
        ['project_id' => $this->project->id, 'batch_id' => (string) Str::uuid(), 'views' => 1, 'matched_views' => 1, 'received_at' => now()->subHours(73)],
        ['project_id' => $this->project->id, 'batch_id' => $recentBatch = (string) Str::uuid(), 'views' => 1, 'matched_views' => 1, 'received_at' => now()->subHours(71)],
    ]);
    StatsViewPending::insert([
        ['project_id' => $this->project->id, 'batch_id' => (string) Str::uuid(), 'visitor_hash' => 'old', 'page' => '/', 'views' => 1, 'last_viewed_at' => now(), 'created_at' => now()->subHours(4)],
        ['project_id' => $this->project->id, 'batch_id' => (string) Str::uuid(), 'visitor_hash' => 'recent', 'page' => '/', 'views' => 1, 'last_viewed_at' => now(), 'created_at' => now()->subHours(2)],
    ]);

    $this->artisan('model:prune', ['--model' => [StatsViewBatch::class, StatsViewPending::class]])->assertSuccessful();

    expect(StatsViewBatch::pluck('batch_id')->all())->toBe([$recentBatch])
        ->and(StatsViewPending::pluck('visitor_hash')->all())->toBe(['recent']);
});
