<?php

use App\Mcp\Servers\SimpleStatsServer;
use App\Mcp\Tools\GetGroupedBreakdown;
use App\Mcp\Tools\GetKpiStats;
use App\Mcp\Tools\ListStatsCapabilities;
use App\Models\GscConnection;
use App\Models\Project;
use App\Models\User;
use App\Services\StatsViewIngestService;
use Database\Seeders\TestUserSeeder;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function (): void {
    $this->seed(TestUserSeeder::class);
    $this->user = User::where('email', config('app.test.user')['email'])->firstOrFail();
    $this->project = Project::first();
    $this->team = $this->project->team;

    DB::table('stats_payments')->delete();
    DB::table('stats_visitors')->delete();
    DB::table('stats_users')->delete();

    auth()->setUser($this->user);
    Context::add('team_id', $this->team->id);
});

function aiToolInsertVisitors(int $teamId, int $projectId, string $time, int $count, ?int $refererId = null): void
{
    for ($i = 0; $i < $count; $i++) {
        DB::table('stats_visitors')->insert([
            'team_id' => $teamId,
            'project_id' => $projectId,
            'visitor_hash' => Str::uuid()->toString(),
            'stats_track_referer_id' => $refererId,
            'time' => $time,
            'time_utc' => $time,
        ]);
    }
}

function aiToolInsertUserWithPayment(int $teamId, int $projectId, int $refererId, string $time, float $net): string
{
    $userId = (string) Str::uuid();

    DB::table('stats_users')->insert([
        'id' => $userId,
        'team_id' => $teamId,
        'project_id' => $projectId,
        'stats_track_referer_id' => $refererId,
        'time' => $time,
        'time_utc' => $time,
        'registered' => true,
    ]);

    DB::table('stats_payments')->insert([
        'id' => (string) Str::uuid(),
        'stats_user_id' => $userId,
        'team_id' => $teamId,
        'project_id' => $projectId,
        'gross' => $net * 1.2,
        'net' => $net,
        'currency' => 'EUR',
        'time' => $time,
        'time_utc' => $time,
        'counter' => 1,
    ]);

    return $userId;
}

function aiToolMakeReferer(int $teamId, string $name): int
{
    return DB::table('stats_track_referers')->insertGetId([
        'team_id' => $teamId,
        'name' => $name,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

it('lists capabilities with projects, dimensions, presets and kpis', function (): void {
    SimpleStatsServer::actingAs($this->user)
        ->tool(ListStatsCapabilities::class)
        ->assertOk()
        ->assertSee('All projects')
        ->assertSee($this->project->name)
        ->assertSee('track_referer')
        ->assertSee('last_month')
        ->assertSee('pd_reg');
});

it('returns kpi stats with totals for a preset range', function (): void {
    $time = now()->subDay()->format('Y-m-d').' 12:00:00';
    aiToolInsertVisitors($this->team->id, $this->project->id, $time, 5);

    SimpleStatsServer::actingAs($this->user)
        ->tool(GetKpiStats::class, ['preset' => 'last_7_days', 'project_id' => $this->project->id])
        ->assertOk()
        ->assertStructuredContent(function ($json): void {
            $json->where('totals.pd_visitor', fn ($count): bool => (float) $count === 5.0)
                ->count('rows', 7)
                ->etc();
        });
});

it('adds per-project totals when the scope is all projects', function (): void {
    $secondProject = Project::factory()
        ->state(['name' => 'Second App', 'usage' => $this->project->usage])
        ->for($this->team)
        ->create();

    $time = now()->subDay()->format('Y-m-d').' 12:00:00';
    aiToolInsertVisitors($this->team->id, $this->project->id, $time, 5);
    aiToolInsertVisitors($this->team->id, $secondProject->id, $time, 3);

    SimpleStatsServer::actingAs($this->user)
        ->tool(GetKpiStats::class, ['preset' => 'last_7_days', 'project_id' => 0])
        ->assertOk()
        ->assertStructuredContent(function ($json): void {
            $json->where('totals.pd_visitor', fn ($count): bool => (float) $count === 8.0)
                ->where('per_project.0.project', $this->project->name)
                ->where('per_project.0.totals.pd_visitor', fn ($count): bool => (float) $count === 5.0)
                ->where('per_project.1.project', 'Second App')
                ->where('per_project.1.totals.pd_visitor', fn ($count): bool => (float) $count === 3.0)
                ->etc();
        });
});

it('omits per-project totals for a single project scope', function (): void {
    $time = now()->subDay()->format('Y-m-d').' 12:00:00';
    aiToolInsertVisitors($this->team->id, $this->project->id, $time, 5);

    SimpleStatsServer::actingAs($this->user)
        ->tool(GetKpiStats::class, ['preset' => 'last_7_days', 'project_id' => $this->project->id])
        ->assertOk()
        ->assertStructuredContent(function ($json): void {
            $json->missing('per_project')->etc();
        });
});

it('rejects a call without any date range', function (): void {
    SimpleStatsServer::actingAs($this->user)
        ->tool(GetKpiStats::class, [])
        ->assertHasErrors();
});

it('breaks down revenue by referrer', function (): void {
    $time = now()->subDay()->format('Y-m-d').' 12:00:00';

    $googleId = aiToolMakeReferer($this->team->id, 'google.com');
    $duckId = aiToolMakeReferer($this->team->id, 'duckduckgo.com');

    // Payment amounts are stored in cents: 10000 = 100.00 in team currency.
    aiToolInsertUserWithPayment($this->team->id, $this->project->id, $googleId, $time, 10000.0);
    aiToolInsertUserWithPayment($this->team->id, $this->project->id, $duckId, $time, 2500.0);

    SimpleStatsServer::actingAs($this->user)
        ->tool(GetGroupedBreakdown::class, [
            'dimension' => 'track_referer',
            'sort' => 'net',
            'preset' => 'last_7_days',
            'project_id' => $this->project->id,
        ])
        ->assertOk()
        ->assertSee('google.com')
        ->assertSee('duckduckgo.com')
        ->assertStructuredContent(function ($json): void {
            $json->where('rows.0.name', 'google.com')
                ->where('rows.0.net', fn ($net): bool => (float) $net === 100.0)
                ->where('scope.currency', 'EUR')
                ->etc();
        });
});

it('rejects an unknown dimension', function (): void {
    SimpleStatsServer::actingAs($this->user)
        ->tool(GetGroupedBreakdown::class, [
            'dimension' => 'not_a_dimension',
            'preset' => 'last_7_days',
        ])
        ->assertHasErrors();
});

it('scopes stats to the acting user\'s team', function (): void {
    $time = now()->subDay()->format('Y-m-d').' 12:00:00';
    aiToolInsertVisitors($this->team->id, $this->project->id, $time, 5);

    $otherUser = User::factory()->withPersonalTeam()->create();

    auth()->forgetGuards();
    Context::flush();
    auth()->setUser($otherUser);
    Context::add('team_id', $otherUser->personalTeam()->id);

    SimpleStatsServer::actingAs($otherUser)
        ->tool(GetKpiStats::class, ['preset' => 'last_7_days'])
        ->assertOk()
        ->assertStructuredContent(function ($json): void {
            $json->where('totals.pd_visitor', fn ($count): bool => (float) $count === 0.0)->etc();
        });
});

function aiToolIngestViews(Project $project, string $time, array $viewsPerPage): void
{
    DB::table('stats_visitors')->insert([
        'team_id' => $project->team_id, 'project_id' => $project->id, 'visitor_hash' => 'viewer',
        'time' => $time, 'time_utc' => $time,
    ]);

    $items = collect($viewsPerPage)->map(fn (int $views, string $page): array => [
        'visitor_hash' => 'viewer', 'page' => $page, 'views' => $views, 'last_viewed_at' => now()->getTimestamp(),
    ])->values()->all();

    resolve(StatsViewIngestService::class)->ingest($project, (string) Str::uuid(), $items);
}

it('reports page view totals and ratios only for projects that track page views', function (): void {
    $time = now()->subDay()->format('Y-m-d').' 12:00:00';
    aiToolInsertVisitors($this->team->id, $this->project->id, $time, 2);

    SimpleStatsServer::actingAs($this->user)
        ->tool(GetKpiStats::class, ['preset' => 'last_7_days', 'project_id' => $this->project->id])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json->missing('totals.pd_view')->etc());

    aiToolIngestViews($this->project, $time, ['/' => 2, '/docs' => 1]);

    SimpleStatsServer::actingAs($this->user)
        ->tool(GetKpiStats::class, ['preset' => 'last_7_days', 'project_id' => $this->project->id])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('totals.pd_view', fn ($views): bool => (float) $views === 3.0)
            ->where('totals.pd_vpv', fn ($vpv): bool => (float) $vpv === 3.0)
            ->where('totals.pd_bounce_rate', fn ($rate): bool => (float) $rate === 0.0)
            ->etc());
});

it('lists top pages as a breakdown dimension and flags projects with page views', function (): void {
    aiToolIngestViews($this->project, now()->subDay()->format('Y-m-d').' 12:00:00', ['/pricing' => 4, '/' => 1]);

    SimpleStatsServer::actingAs($this->user)
        ->tool(ListStatsCapabilities::class)
        ->assertOk()
        ->assertSee('top_pages')
        ->assertSee('"tracks_page_views":true');

    SimpleStatsServer::actingAs($this->user)
        ->tool(GetGroupedBreakdown::class, [
            'dimension' => 'top_pages',
            'preset' => 'last_7_days',
            'project_id' => $this->project->id,
        ])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('rows.0.name', 'pricing/')
            ->where('rows.0.views', 4)
            ->etc());
});

it('offers the Google keywords breakdown and links the setup page until Search Console is connected', function (): void {
    config(['services.google_search_console.client_id' => 'client-id', 'services.google_search_console.client_secret' => 'client-secret']);

    SimpleStatsServer::actingAs($this->user)
        ->tool(ListStatsCapabilities::class)
        ->assertOk()
        ->assertSee('"search_console_connected":false')
        ->assertSee('search-console');

    GscConnection::factory()->create(['project_id' => $this->project->id]);
    DB::table('stats_keyword_daily')->insert([
        'team_id' => $this->project->team_id, 'project_id' => $this->project->id, 'date' => now()->subDays(3)->toDateString(),
        'stats_track_keyword_id' => DB::table('stats_track_keywords')->insertGetId(['team_id' => $this->project->team_id, 'name' => 'laravel analytics']),
        'stats_page_id' => DB::table('stats_pages')->insertGetId(['team_id' => $this->project->team_id, 'name' => '/']),
        'clicks' => 12, 'impressions' => 300, 'position' => 4.2,
    ]);

    SimpleStatsServer::actingAs($this->user)
        ->tool(GetGroupedBreakdown::class, [
            'dimension' => 'keywords',
            'preset' => 'last_7_days',
            'project_id' => $this->project->id,
        ])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('rows.0.name', 'laravel analytics')
            ->where('rows.0.clicks', 12)
            ->etc());
});
