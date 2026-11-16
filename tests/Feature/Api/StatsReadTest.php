<?php

use App\Models\Project;
use App\Models\Team;
use App\Models\User;
use App\Services\StatsViewIngestService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function (): void {
    $this->seed();
    $this->user = User::where('email', config('app.test.user')['email'])->firstOrFail();
    $this->project = Project::first();
    $this->token = $this->project->createToken('test-token')->plainTextToken;

    // TestUserSeeder leaves the test user on the web guard; forget it so the
    // Bearer token (the project) wins over Sanctum's stateful session check.
    auth()->forgetGuards();
});

test('stats index without auth returns 401', function (): void {
    auth()->forgetGuards();

    $response = $this->getJson('/api/v1/stats', [
        'Authorization' => 'Bearer invalid-token',
        'Accept' => 'application/json',
    ]);

    $response->assertUnauthorized();
});

test('stats index with valid token returns 200 with correct structure', function (): void {
    $response = $this->getJson('/api/v1/stats', apiHeaders($this->token));

    $response->assertOk()
        ->assertJsonStructure([
            'data',
            'meta' => [
                'project_id',
                'range_type',
                'range_amount',
                'range_start',
                'preset',
                'comparison',
            ],
        ]);
});

test('stats index defaults to last 7 days when no parameters given', function (): void {
    $response = $this->getJson('/api/v1/stats', apiHeaders($this->token));

    $response->assertOk()
        ->assertJsonPath('meta.preset', 'last_7_days')
        ->assertJsonPath('meta.range_type', 'days')
        ->assertJsonPath('meta.range_amount', 7);
});

test('stats index with preset parameter applies preset', function (): void {
    $response = $this->getJson('/api/v1/stats?preset=last_30_days', apiHeaders($this->token));

    $response->assertOk()
        ->assertJsonPath('meta.preset', 'last_30_days')
        ->assertJsonPath('meta.range_type', 'days')
        ->assertJsonPath('meta.range_amount', 30);
});

test('stats index with comparison returns data_previous', function (): void {
    $response = $this->getJson('/api/v1/stats?preset=last_7_days&comparison=period', apiHeaders($this->token));

    $response->assertOk()
        ->assertJsonStructure([
            'data',
            'data_previous',
            'meta',
        ]);
});

test('stats index without comparison does not return data_previous', function (): void {
    $response = $this->getJson('/api/v1/stats', apiHeaders($this->token));

    $response->assertOk();
    expect($response->json())->not->toHaveKey('data_previous');
});

test('stats index with invalid preset returns 422', function (): void {
    $response = $this->getJson('/api/v1/stats?preset=invalid_preset', apiHeaders($this->token));

    $response->assertUnprocessable()
        ->assertJsonValidationErrors('preset');
});

test('stats index with invalid comparison returns 422', function (): void {
    $response = $this->getJson('/api/v1/stats?comparison=invalid', apiHeaders($this->token));

    $response->assertUnprocessable()
        ->assertJsonValidationErrors('comparison');
});

test('stats grouped without auth returns 401', function (): void {
    auth()->forgetGuards();

    $response = $this->getJson('/api/v1/stats/grouped?stats_type=track_source', [
        'Authorization' => 'Bearer invalid-token',
        'Accept' => 'application/json',
    ]);

    $response->assertUnauthorized();
});

test('stats grouped with valid token returns 200 with correct structure', function (): void {
    $response = $this->getJson('/api/v1/stats/grouped?stats_type=track_source', apiHeaders($this->token));

    $response->assertOk()
        ->assertJsonStructure([
            'data',
            'meta' => [
                'project_id',
                'range_type',
                'range_amount',
                'range_start',
                'preset',
                'comparison',
            ],
        ]);
});

test('stats grouped without statsType returns 422', function (): void {
    $response = $this->getJson('/api/v1/stats/grouped', apiHeaders($this->token));

    $response->assertUnprocessable()
        ->assertJsonValidationErrors('stats_type');
});

test('stats grouped with invalid statsType returns 422', function (): void {
    $response = $this->getJson('/api/v1/stats/grouped?stats_type=invalid_type', apiHeaders($this->token));

    $response->assertUnprocessable()
        ->assertJsonValidationErrors('stats_type');
});

test('stats grouped rejects the dashboard-only property type', function (): void {
    // "property" is a dashboard-only stats_type (see StatsFilterRequest),
    // the public API whitelist must not accept it
    $response = $this->getJson('/api/v1/stats/grouped?stats_type=property', apiHeaders($this->token));

    $response->assertUnprocessable()
        ->assertJsonValidationErrors('stats_type');
});

test('stats grouped with limit parameter limits results', function (): void {
    $response = $this->getJson('/api/v1/stats/grouped?stats_type=track_source&limit=3', apiHeaders($this->token));

    $response->assertOk();
    expect(count($response->json('data')))->toBeLessThanOrEqual(3);
});

test('stats index project_id in meta matches authenticated project', function (): void {
    $response = $this->getJson('/api/v1/stats', apiHeaders($this->token));

    $response->assertOk();
    expect($response->json('meta.project_id'))->toBeInt();
});

test('stats groups returns data for valid statsType', function (): void {
    $response = $this->getJson('/api/v1/stats/groups?stats_type=track_source', apiHeaders($this->token));

    $response->assertOk()
        ->assertJsonStructure([
            'data' => [
                '*' => ['id', 'name'],
            ],
        ]);
});

test('stats groups only returns page entries of the own team', function (): void {
    $foreignTeam = Team::factory()->create();

    DB::table('stats_page_entries')->insert([
        ['team_id' => $this->project->team_id, 'name' => '/own-page', 'created_at' => now(), 'updated_at' => now()],
        ['team_id' => $foreignTeam->id, 'name' => '/foreign-page', 'created_at' => now(), 'updated_at' => now()],
    ]);

    $response = $this->getJson('/api/v1/stats/groups?stats_type=page_entry', apiHeaders($this->token));

    $response->assertOk();
    $names = collect($response->json('data'))->pluck('name');
    expect($names)->toContain('/own-page')
        ->not->toContain('/foreign-page');
});

test('stats groups without statsType returns 422', function (): void {
    $response = $this->getJson('/api/v1/stats/groups', apiHeaders($this->token));

    $response->assertUnprocessable()
        ->assertJsonValidationErrors('stats_type');
});

test('stats groups with invalid statsType returns 422', function (): void {
    $response = $this->getJson('/api/v1/stats/groups?stats_type=invalid', apiHeaders($this->token));

    $response->assertUnprocessable()
        ->assertJsonValidationErrors('stats_type');
});

test('stats groups without auth returns 401', function (): void {
    auth()->forgetGuards();

    $response = $this->getJson('/api/v1/stats/groups?stats_type=track_source', [
        'Authorization' => 'Bearer invalid-token',
        'Accept' => 'application/json',
    ]);

    $response->assertUnauthorized();
});

test('stats grouped still works while the team owner is soft deleted', function (): void {
    // Inactive owners are soft-deleted for a two month grace window while their
    // team, projects and API tokens keep running (SIMPLESTATS-7F).
    $this->project->team->owner->delete();
    auth()->forgetGuards();

    $response = $this->getJson('/api/v1/stats/grouped?stats_type=track_source', apiHeaders($this->token));

    $response->assertOk();
});

test('the api rate limit still resolves the plan of a soft deleted owner', function (): void {
    $team = $this->project->team;
    $owner = $team->owner;
    $owner->delete();

    expect($team->fresh()->owner)->toBeNull();
    expect($team->fresh()->billingOwner->id)->toBe($owner->id);
});

test('stats grouped serves top pages and view columns once the project has page views', function (): void {
    DB::table('stats_visitors')->insert([
        'team_id' => $this->project->team_id, 'project_id' => $this->project->id, 'visitor_hash' => 'api-viewer',
        'stats_track_source_id' => null, 'time' => now()->format('Y-m-d H:i:s'), 'time_utc' => now()->format('Y-m-d H:i:s'),
    ]);
    resolve(StatsViewIngestService::class)->ingest($this->project, (string) Str::uuid(), [
        ['visitor_hash' => 'api-viewer', 'page' => '/pricing', 'views' => 3, 'last_viewed_at' => now()->getTimestamp()],
    ]);

    $topPages = $this->getJson('/api/v1/stats/grouped?stats_type=top_pages&preset=last_7_days', apiHeaders($this->token));
    $sources = $this->getJson('/api/v1/stats/grouped?stats_type=track_referer&preset=last_7_days&limit=100', apiHeaders($this->token));

    $topPages->assertOk()->assertJsonPath('data.0.name', 'pricing/')->assertJsonPath('data.0.views', 3);
    $sources->assertOk();
    expect(collect($sources->json('data'))->sum('views'))->toBeGreaterThanOrEqual(3);
});
