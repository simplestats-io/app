<?php

use App\DTOs\StatsFilterDto;
use App\Enums\StatsRangeType;
use App\Models\Project;
use App\Models\User;
use App\Services\StatsDetailsService;
use App\Services\StatsViewIngestService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Database\Seeders\TestUserSeeder;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function (): void {
    $this->seed(TestUserSeeder::class);
    $this->user = User::where('email', config('app.test.user')['email'])->firstOrFail();
    $this->project = Project::first();
    $this->team = $this->project->team;
    $this->team->update(['timezone' => 'UTC']);

    DB::table('stats_visitors')->delete();

    Carbon::setTestNow(Carbon::parse('2026-03-30 12:00:00'));
    auth()->setUser($this->user);
    Context::add('team_id', $this->team->id);
});

afterEach(function (): void {
    Carbon::setTestNow();
});

function dashboardViewVisitor(Project $project, string $hash): void
{
    DB::table('stats_visitors')->insert([
        'team_id' => $project->team_id, 'project_id' => $project->id, 'visitor_hash' => $hash,
        'time' => '2026-03-30 10:00:00', 'time_utc' => '2026-03-30 10:00:00',
    ]);
}

function dashboardIngest(Project $project, array $viewsPerHash): void
{
    $items = [];

    foreach ($viewsPerHash as $hash => $pages) {
        foreach ($pages as $page => $views) {
            $items[] = ['visitor_hash' => $hash, 'page' => $page, 'views' => $views, 'last_viewed_at' => now()->getTimestamp()];
        }
    }

    resolve(StatsViewIngestService::class)->ingest($project, (string) Str::uuid(), $items);
}

function groupedQuery(Project $project, array $extra = []): array
{
    return array_merge([
        'project_id' => $project->id,
        'range_type' => StatsRangeType::DAY->value,
        'range_amount' => 1,
        'range_start' => '2026-03-30',
        'comparison' => '0',
    ], $extra);
}

it('serves the top pages tab through the grouped endpoint', function (): void {
    dashboardViewVisitor($this->project, 'a');
    dashboardViewVisitor($this->project, 'b');
    dashboardIngest($this->project, ['a' => ['/' => 2, '/docs' => 1], 'b' => ['/docs' => 4]]);

    $response = $this->actingAs($this->user)->getJson(route('stats.grouped', groupedQuery($this->project, [
        'stats_type' => 'top_pages',
        'stats_sort' => 'views',
    ])));

    $response->assertOk();
    expect(collect($response->json('data'))->map(fn (array $row): array => [$row['name'], $row['views'], $row['visitors']])->all())
        ->toBe([['docs/', 5, 2], ['/', 2, 1]]);
});

it('rejects an unknown stats type on the grouped endpoint with 422', function (): void {
    $this->actingAs($this->user)->getJson(route('stats.grouped', groupedQuery($this->project, ['stats_type' => 'exit_pages'])))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['stats_type']);
});

it('shares the page views state with the dashboard', function (): void {
    $this->project->update(['page_views_since' => '2026-03-20 08:00:00', 'page_views_seen_at' => '2026-03-29']);

    $this->actingAs($this->user)->get(route('dashboard', ['project_id' => $this->project->id]))
        ->assertInertia(fn ($page) => $page
            ->where('pageViews.visible', true)
            ->where('pageViews.since', '2026-03-20 08:00:00'));
});

it('hides page views on the dashboard of a project that never received any', function (): void {
    $this->actingAs($this->user)->get(route('dashboard', ['project_id' => $this->project->id]))
        ->assertInertia(fn ($page) => $page->where('pageViews.visible', false)->where('pageViews.since', null));
});

it('derives views per visitor and bounce rate per bucket from the cached counters', function (): void {
    dashboardViewVisitor($this->project, 'a');
    dashboardViewVisitor($this->project, 'b');
    dashboardViewVisitor($this->project, 'no-views');
    dashboardIngest($this->project, ['a' => ['/' => 1], 'b' => ['/' => 2, '/docs' => 1]]);

    $stats = resolve(StatsDetailsService::class)->getStats(new StatsFilterDto(
        projectId: $this->project->id, rangeAmount: 1, rangeType: StatsRangeType::DAY,
        rangeStart: CarbonImmutable::parse('2026-03-30'), comparison: '0',
    ));

    $bucket = collect($stats)->firstWhere('date_clear', '2026-03-30') ?? collect($stats)->first();

    expect($bucket['pd_view'])->toBe(4)
        ->and($bucket['pd_vpv'])->toBe(2.0)
        ->and($bucket['pd_bounce_rate'])->toBe(50.0);
});
