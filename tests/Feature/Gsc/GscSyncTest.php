<?php

use App\Jobs\SyncGscDataJob;
use App\Models\GscConnection;
use App\Models\Project;
use App\Models\StatsKeywordDaily;
use App\Models\User;
use App\Services\Gsc\GscSyncService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    config(['services.google_search_console.client_id' => 'client-id', 'services.google_search_console.client_secret' => 'client-secret']);

    $owner = User::factory()->withPersonalTeam()->create();
    $this->team = $owner->currentTeam;
    $this->project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->connection = GscConnection::factory()->create(['project_id' => $this->project->id]);
});

function gscRow(string $date, string $query, string $page, int $clicks, int $impressions, float $position): array
{
    return ['keys' => [$date, $query, $page], 'clicks' => $clicks, 'impressions' => $impressions, 'ctr' => 0, 'position' => $position];
}

function gscFakeRows(array $rows): void
{
    Http::fake(['searchconsole.googleapis.com/*' => Http::response(['rows' => $rows])]);
}

function gscStoredRows(Project $project): array
{
    return DB::table('stats_keyword_daily as k')
        ->join('stats_track_keywords as stk', 'stk.id', '=', 'k.stats_track_keyword_id')
        ->join('stats_pages as sp', 'sp.id', '=', 'k.stats_page_id')
        ->where('k.project_id', $project->id)
        ->orderBy('stk.name')
        ->get(['k.date', 'stk.name as keyword', 'sp.name as page', 'k.clicks', 'k.impressions', 'k.position'])
        ->map(fn (object $row): array => [$row->date, $row->keyword, $row->page, $row->clicks, $row->impressions, (float) $row->position])
        ->all();
}

it('stores keyword rows per landing page with impression weighted positions', function (): void {
    gscFakeRows([
        gscRow('2026-11-01', 'laravel analytics', 'https://example.com/pricing/', 3, 10, 2.0),
        // Same page after normalization: merged, position weighted by impressions.
        gscRow('2026-11-01', 'laravel analytics', 'https://example.com/pricing?ref=x', 1, 30, 6.0),
        gscRow('2026-11-01', 'server side tracking', 'https://www.example.com/', 0, 5, 12.0),
    ]);

    resolve(GscSyncService::class)->syncWindow($this->connection, CarbonImmutable::parse('2026-11-01'), CarbonImmutable::parse('2026-11-01'));

    expect(gscStoredRows($this->project))->toBe([
        ['2026-11-01', 'laravel analytics', '/pricing', 4, 40, 5.0],
        ['2026-11-01', 'server side tracking', '/', 0, 5, 12.0],
    ]);

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), rawurlencode('sc-domain:example.com'))
        && $request['dimensions'] === ['date', 'query', 'page']);
});

it('replaces the synced window so keywords dropped by Google disappear', function (): void {
    Http::fake(['searchconsole.googleapis.com/*' => Http::sequence()
        ->push(['rows' => [gscRow('2026-11-01', 'old keyword', 'https://example.com/', 1, 1, 1)]])
        ->push(['rows' => [gscRow('2026-11-01', 'new keyword', 'https://example.com/', 2, 2, 1)]]),
    ]);

    $day = CarbonImmutable::parse('2026-11-01');
    resolve(GscSyncService::class)->syncWindow($this->connection, $day, $day);
    resolve(GscSyncService::class)->syncWindow($this->connection, $day, $day);

    expect(array_column(gscStoredRows($this->project), 1))->toBe(['new keyword']);
});

it('keeps the rows of two projects with the same keyword and page apart', function (): void {
    $other = GscConnection::factory()->create([
        'project_id' => Project::factory()->create(['team_id' => $this->team->id])->id,
    ]);

    gscFakeRows([gscRow('2026-11-01', 'laravel analytics', 'https://example.com/', 5, 10, 1)]);
    resolve(GscSyncService::class)->syncWindow($this->connection, CarbonImmutable::parse('2026-11-01'), CarbonImmutable::parse('2026-11-01'));
    resolve(GscSyncService::class)->syncWindow($other, CarbonImmutable::parse('2026-11-01'), CarbonImmutable::parse('2026-11-01'));

    expect(StatsKeywordDaily::withoutGlobalScopes()->count())->toBe(2)
        ->and(StatsKeywordDaily::withoutGlobalScopes()->distinct()->count('stats_track_keyword_id'))->toBe(1);
});

it('refreshes an expired access token before querying', function (): void {
    $this->connection->update(['expires_at' => now()->subMinute()]);

    Http::fake([
        'oauth2.googleapis.com/token' => Http::response(['access_token' => 'fresh-token', 'expires_in' => 3600]),
        'searchconsole.googleapis.com/*' => Http::response(['rows' => []]),
    ]);

    resolve(GscSyncService::class)->syncRecent($this->connection);

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'searchconsole')
        && $request->hasHeader('Authorization', 'Bearer fresh-token'));
    expect($this->connection->fresh()->access_token)->toBe('fresh-token')
        ->and($this->connection->fresh()->last_synced_until_date)->not->toBeNull();
});

it('flags the connection for a reconnect when Google revoked the grant', function (): void {
    $this->connection->update(['expires_at' => now()->subMinute()]);

    Http::fake(['oauth2.googleapis.com/token' => Http::response(['error' => 'invalid_grant'], 400)]);

    dispatch_sync(new SyncGscDataJob($this->connection));

    expect($this->connection->fresh()->reconnect_required_at)->not->toBeNull();

    // Flagged connections are skipped by the daily schedule.
    Bus::fake();
    $this->artisan('app:sync-gsc-data')->assertSuccessful();
    Bus::assertNotDispatched(SyncGscDataJob::class);
});

it('flags the connection when the property is no longer accessible', function (): void {
    Http::fake(['searchconsole.googleapis.com/*' => Http::response([], 403)]);

    dispatch_sync(new SyncGscDataJob($this->connection));

    expect($this->connection->fresh()->reconnect_required_at)->not->toBeNull();
});

it('queues a 16 month backfill as independent weekly windows without gaps, newest first', function (): void {
    Bus::fake();

    resolve(GscSyncService::class)->queueBackfill($this->connection);

    $windows = Bus::dispatched(SyncGscDataJob::class)->values();
    $end = resolve(GscSyncService::class)->lastAvailableDate();

    expect($windows->first()->endDate)->toBe($end->toDateString())
        ->and($windows->last()->startDate)->toBe($end->subMonths(GscSyncService::BACKFILL_MONTHS)->toDateString())
        ->and($windows->every(fn (SyncGscDataJob $job): bool => $job->queue === 'low'))->toBeTrue()
        ->and($this->connection->fresh()->last_synced_until_date->toDateString())->toBe($end->toDateString());

    foreach ($windows->sliding(2) as $pair) {
        expect(CarbonImmutable::parse($pair->last()->endDate)->addDay()->toDateString())->toBe($pair->first()->startDate);
    }
});

it('refreshes and retries once when Google rejects a token that looked valid', function (): void {
    Http::fake([
        'oauth2.googleapis.com/token' => Http::response(['access_token' => 'fresh-token', 'expires_in' => 3600]),
        'searchconsole.googleapis.com/*' => Http::sequence()->push([], 401)->whenEmpty(Http::response(['rows' => []])),
    ]);

    dispatch_sync(new SyncGscDataJob($this->connection));

    expect($this->connection->fresh()->reconnect_required_at)->toBeNull()
        ->and($this->connection->fresh()->access_token)->toBe('fresh-token');
});

it('writes nothing for a connection that was disconnected during the fetch', function (): void {
    gscFakeRows([gscRow('2026-11-01', 'laravel analytics', 'https://example.com/', 1, 1, 1)]);
    $stale = $this->connection->replicate()->forceFill(['id' => $this->connection->id]);
    $this->connection->delete();

    resolve(GscSyncService::class)->syncWindow($stale, CarbonImmutable::parse('2026-11-01'), CarbonImmutable::parse('2026-11-01'));

    expect(StatsKeywordDaily::withoutGlobalScopes()->count())->toBe(0);
});
