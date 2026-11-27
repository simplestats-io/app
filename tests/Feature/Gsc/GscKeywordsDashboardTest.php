<?php

use App\DTOs\StatsFilterDto;
use App\Enums\StatsRangeType;
use App\Models\GscConnection;
use App\Models\Project;
use App\Models\StatsPageEntry;
use App\Models\Team;
use App\Models\User;
use App\Services\StatsAggregationsService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function (): void {
    config(['services.google_search_console.client_id' => 'client-id', 'services.google_search_console.client_secret' => 'client-secret']);

    $this->owner = User::factory()->withPersonalTeam()->create();
    $this->team = $this->owner->currentTeam;
    $this->team->update(['timezone' => 'UTC']);
    $this->project = Project::factory()->create(['team_id' => $this->team->id]);

    $this->actingAs($this->owner);
    Context::add('team_id', $this->team->id);
});

function gscForeignTeamId(): int
{
    $other = User::factory()->withPersonalTeam()->create();

    return Team::withoutGlobalScopes()->where('user_id', $other->id)->value('id');
}

function gscKeywordRow(Project $project, string $keyword, string $page, int $clicks, int $impressions = 100, float $position = 5.0, string $date = '2026-11-10'): void
{
    DB::table('stats_keyword_daily')->insert([
        'team_id' => $project->team_id,
        'project_id' => $project->id,
        'date' => $date,
        'stats_track_keyword_id' => gscLookupId('stats_track_keywords', $project->team_id, $keyword),
        'stats_page_id' => gscLookupId('stats_pages', $project->team_id, $page),
        'clicks' => $clicks,
        'impressions' => $impressions,
        'position' => $position,
    ]);
}

function gscLookupId(string $table, int $teamId, string $name): int
{
    DB::table($table)->insertOrIgnore(['team_id' => $teamId, 'name' => $name]);

    return DB::table($table)->where('team_id', $teamId)->where('name', $name)->value('id');
}

function gscEntryPage(Project $project, string $page): int
{
    return StatsPageEntry::withoutGlobalScopes()->firstOrCreate(['team_id' => $project->team_id, 'name' => $page])->id;
}

function gscPayingUser(Project $project, string $userId, string $landingPage, float $net): void
{
    DB::table('stats_users')->insert([
        'id' => $userId, 'team_id' => $project->team_id, 'project_id' => $project->id,
        'stats_page_entry_id' => gscEntryPage($project, $landingPage),
        'time' => '2026-11-10 12:00:00', 'time_utc' => '2026-11-10 12:00:00',
    ]);

    DB::table('stats_payments')->insert([
        'id' => (string) Str::uuid(), 'stats_user_id' => $userId, 'team_id' => $project->team_id, 'project_id' => $project->id,
        'gross' => $net, 'net' => $net, 'currency' => 'EUR', 'counter' => 1,
        'time' => '2026-11-10 12:00:00', 'time_utc' => '2026-11-10 12:00:00',
    ]);
}

function gscVisitors(Project $project, string $landingPage, int $count): void
{
    foreach (range(1, $count) as $i) {
        DB::table('stats_visitors')->insert([
            'team_id' => $project->team_id, 'project_id' => $project->id, 'visitor_hash' => bin2hex(random_bytes(16)),
            'stats_page_entry_id' => gscEntryPage($project, $landingPage),
            'time' => '2026-11-10 10:00:00', 'time_utc' => '2026-11-10 10:00:00',
        ]);
    }
}

function gscKeywords(Project $project, string $sort = 'clicks', int $pageEntry = 0): array
{
    $filters = new StatsFilterDto(
        projectId: $project->id,
        rangeAmount: 30,
        rangeType: StatsRangeType::DAY,
        rangeStart: CarbonImmutable::parse('2026-11-30'),
        comparison: '0',
        pageEntry: $pageEntry,
    );

    return resolve(StatsAggregationsService::class)->getKeywordsData($filters, $sort, 50)->keyBy('name')->all();
}

it('aggregates clicks, impressions, ctr and the impression weighted position per keyword', function (): void {
    gscKeywordRow($this->project, 'laravel analytics', '/', clicks: 8, impressions: 100, position: 2.0);
    gscKeywordRow($this->project, 'laravel analytics', '/pricing', clicks: 2, impressions: 300, position: 6.0, date: '2026-11-11');
    gscKeywordRow($this->project, 'never clicked', '/', clicks: 0, impressions: 50, position: 30.0);

    $rows = gscKeywords($this->project);

    expect(array_keys($rows))->toBe(['laravel analytics', 'never clicked'])
        ->and($rows['laravel analytics'])->toMatchArray(['clicks' => 10, 'impressions' => 400, 'ctr' => 2.5, 'position' => 5.0])
        ->and($rows['never clicked']['position'])->toBe(30.0);
});

it('ignores rows outside the range, of other projects and of other teams', function (): void {
    gscKeywordRow($this->project, 'in range', '/', clicks: 1);
    gscKeywordRow($this->project, 'too old', '/', clicks: 1, date: '2026-10-01');
    gscKeywordRow(Project::factory()->create(['team_id' => $this->team->id]), 'other project', '/', clicks: 1);

    $foreign = Project::withoutEvents(fn (): Project => Project::factory()->create(['team_id' => gscForeignTeamId()]));
    gscKeywordRow($foreign, 'foreign team', '/', clicks: 1);

    expect(array_keys(gscKeywords($this->project)))->toBe(['in range']);
});

it('estimates revenue by click share of the landing page, capped by its visitors', function (): void {
    gscKeywordRow($this->project, 'k1', '/pricing', clicks: 30);
    gscKeywordRow($this->project, 'k2', '/pricing', clicks: 10);
    gscPayingUser($this->project, 'u1', '/pricing', 100.0);

    // 40 Google clicks, 40 visitors: Google brought all traffic, k1 gets 30/40.
    gscVisitors($this->project, '/pricing', 40);
    expect(gscKeywords($this->project)['k1']['est_net'])->toBe(75.0)
        ->and(gscKeywords($this->project)['k2']['est_net'])->toBe(25.0);

    // 80 more visitors from elsewhere: Google brought a third, k1 gets 30/120.
    gscVisitors($this->project, '/pricing', 80);
    expect(gscKeywords($this->project)['k1']['est_net'])->toBe(25.0);
});

it('narrows keywords to an entry page filter by its path', function (): void {
    gscKeywordRow($this->project, 'pricing keyword', '/pricing', clicks: 3);
    gscKeywordRow($this->project, 'home keyword', '/', clicks: 5);

    expect(array_keys(gscKeywords($this->project, pageEntry: gscEntryPage($this->project, '/pricing'))))->toBe(['pricing keyword'])
        // "Direct / Unknown" entry page: no keyword leads there.
        ->and(gscKeywords($this->project, pageEntry: -1))->toBe([]);
});

it('serves the keywords tab through the grouped endpoint and shows it only with a linked property', function (): void {
    gscKeywordRow($this->project, 'laravel analytics', '/', clicks: 3);

    $query = [
        'project_id' => $this->project->id, 'range_type' => StatsRangeType::DAY->value, 'range_amount' => 30,
        'range_start' => '2026-11-30', 'comparison' => '0', 'stats_type' => 'keywords', 'stats_sort' => 'position',
    ];

    $this->getJson(route('stats.grouped', $query))
        ->assertOk()
        ->assertJsonPath('data.0.name', 'laravel analytics');

    $filters = new StatsFilterDto(projectId: $this->project->id, rangeAmount: 1, rangeType: StatsRangeType::DAY, rangeStart: today(), comparison: '0');
    $service = resolve(StatsAggregationsService::class);

    expect($service->hasSearchConsole($filters))->toBeFalse();

    GscConnection::factory()->create(['project_id' => $this->project->id]);
    expect($service->hasSearchConsole($filters))->toBeTrue();

    config(['services.google_search_console.client_id' => null]);
    expect($service->hasSearchConsole($filters))->toBeFalse();
});
