<?php

use App\Helpers\AppHelper;
use App\Jobs\TrackStatsVisitor;
use App\Location\Position;
use App\Models\Project;
use App\Models\StatsVisitor;
use App\Models\User;
use Database\Seeders\TestUserSeeder;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Context;
use Stevebauman\Location\Facades\Location;

beforeEach(function (): void {
    $this->seed(TestUserSeeder::class);
    $this->user = User::where('email', config('app.test.user')['email'])->firstOrFail();
    $this->project = Project::first();
    $this->token = $this->project->createToken('test-token')->plainTextToken;

    // TestUserSeeder leaves the test user on the web guard; forget it so the
    // Bearer token (the project) wins over Sanctum's stateful session check.
    auth()->forgetGuards();
});

function validVisitorPayload(array $overrides = []): array
{
    return array_merge([
        'time' => now()->format('Y-m-d H:i:s P'),
        'ip' => fake()->ipv4(),
        'user_agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36',
    ], $overrides);
}

function setVisitorProjectContext(?Authenticatable $project): void
{
    auth()->setUser($project);
    AppHelper::setTeamContextFromAuth($project);
    Context::add('project_id', $project->id);
    Context::add('request_url', '/api/v1/stats-visitor');
}

test('store visitor with auth and required fields returns 200', function (): void {
    $response = $this->postJson(
        '/api/v1/stats-visitor',
        validVisitorPayload(),
        apiHeaders($this->token),
    );

    $response->assertOk()
        ->assertJson(['message' => 'Stats Visitor Request accepted.'])
        ->assertJsonPath('visitor_hash', fn (mixed $hash): bool => is_string($hash) && $hash !== '');
});

test('store visitor without auth returns 401', function (): void {
    auth()->forgetGuards();

    $response = $this->postJson(
        '/api/v1/stats-visitor',
        validVisitorPayload(),
        [
            'Authorization' => 'Bearer invalid-token',
            'Accept' => 'application/json',
        ],
    );

    $response->assertUnauthorized();
});

test('validation fails when time is missing', function (): void {
    $payload = validVisitorPayload();
    unset($payload['time']);

    $response = $this->postJson(
        '/api/v1/stats-visitor',
        $payload,
        apiHeaders($this->token),
    );

    $response->assertUnprocessable()
        ->assertJsonValidationErrors('time');
});

test('visitor is created via firstOrCreate using visitor_hash', function (): void {
    $payload = validVisitorPayload([
        'visitor_hash' => 'test-hash-'.fake()->uuid(),
    ]);

    $this->postJson('/api/v1/stats-visitor', $payload, apiHeaders($this->token))
        ->assertOk()
        ->assertJson(['visitor_hash' => $payload['visitor_hash']]);

    setVisitorProjectContext($this->project);

    $visitor = StatsVisitor::where('visitor_hash', $payload['visitor_hash'])->first();
    expect($visitor)->not->toBeNull();
});

test('duplicate visitor_hash does not create a second record', function (): void {
    $visitorHash = 'duplicate-hash-'.fake()->uuid();

    $payload1 = validVisitorPayload(['visitor_hash' => $visitorHash]);
    $this->postJson('/api/v1/stats-visitor', $payload1, apiHeaders($this->token))->assertOk();

    $payload2 = validVisitorPayload(['visitor_hash' => $visitorHash]);
    $this->postJson('/api/v1/stats-visitor', $payload2, apiHeaders($this->token))->assertOk();

    setVisitorProjectContext($this->project);

    $visitors = StatsVisitor::where('visitor_hash', $visitorHash)->get();
    expect($visitors)->toHaveCount(1);
});

test('bot user agents are rejected with 200', function (): void {
    $payload = validVisitorPayload([
        'user_agent' => 'Googlebot/2.1 (+http://www.google.com/bot.html)',
    ]);

    $response = $this->postJson('/api/v1/stats-visitor', $payload, apiHeaders($this->token));

    $response->assertOk()
        ->assertJson(['message' => 'Bots are not allowed']);
});

test('headless browser user agents are rejected with 200', function (): void {
    $payload = validVisitorPayload([
        'user_agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) HeadlessChrome/126.0.0.0 Safari/537.36',
    ]);

    $response = $this->postJson('/api/v1/stats-visitor', $payload, apiHeaders($this->token));

    $response->assertOk()
        ->assertJson(['message' => 'Bots are not allowed']);
});

test('tracking fields are accepted', function (): void {
    $payload = validVisitorPayload([
        'visitor_hash' => 'tracking-hash-'.fake()->uuid(),
        'track_source' => 'google',
        'track_medium' => 'cpc',
        'track_campaign' => 'summer-sale',
        'track_term' => 'analytics',
        'track_content' => 'banner-ad',
        'track_referer' => 'https://google.com',
        'page_entry' => '/landing-page',
        'location_country' => 'DE',
    ]);

    $this->postJson('/api/v1/stats-visitor', $payload, apiHeaders($this->token))->assertOk();

    setVisitorProjectContext($this->project);

    $visitor = StatsVisitor::where('visitor_hash', $payload['visitor_hash'])->first();
    expect($visitor)->not->toBeNull();
});

test('events_count_monthly is incremented on team via StatsObserver', function (): void {
    $team = $this->project->team;
    $initialCount = $team->events_count_monthly;

    $payload = validVisitorPayload();
    $this->postJson('/api/v1/stats-visitor', $payload, apiHeaders($this->token))->assertOk();

    $team->refresh();
    expect($team->events_count_monthly)->toBeGreaterThan($initialCount);
});

test('events_count_monthly is incremented on project via StatsObserver', function (): void {
    $project = $this->project;
    $initialCount = $project->events_count_monthly;

    $payload = validVisitorPayload();
    $this->postJson('/api/v1/stats-visitor', $payload, apiHeaders($this->token))->assertOk();

    $project->refresh();
    expect($project->events_count_monthly)->toBeGreaterThan($initialCount);
});

test('reaching the monthly event limit returns a terminal 200 and does not dispatch the tracking job', function (): void {
    $this->skipUnlessSaas();

    Bus::fake();

    $team = $this->project->team;
    $limit = $team->owner->sparkPlan()->options['Events'];
    $team->update(['events_count_monthly' => $limit]);

    $response = $this->postJson('/api/v1/stats-visitor', validVisitorPayload(), apiHeaders($this->token));

    $response->assertOk()->assertJson(['message' => 'Monthly event limit reached.']);
    Bus::assertNotDispatched(TrackStatsVisitor::class);
});

test('deleting a stat decrements the event counters', function (): void {
    $payload = validVisitorPayload(['visitor_hash' => 'delete-hash-'.fake()->uuid()]);
    $this->postJson('/api/v1/stats-visitor', $payload, apiHeaders($this->token))->assertOk();

    setVisitorProjectContext($this->project);

    $team = $this->project->team->refresh();
    $project = $this->project->fresh();

    $teamMonthlyBefore = $team->events_count_monthly;
    $teamOverallBefore = $team->events_count_overall;
    $projectMonthlyBefore = $project->events_count_monthly;
    expect($teamMonthlyBefore)->toBeGreaterThan(0);

    StatsVisitor::where('visitor_hash', $payload['visitor_hash'])->firstOrFail()->delete();

    $team->refresh();
    $project->refresh();
    expect($team->events_count_monthly)->toBe($teamMonthlyBefore - 1);
    expect($team->events_count_overall)->toBe($teamOverallBefore - 1);
    expect($project->events_count_monthly)->toBe($projectMonthlyBefore - 1);
});

test('deleting a stat never pushes a counter below zero', function (): void {
    $payload = validVisitorPayload(['visitor_hash' => 'floor-hash-'.fake()->uuid()]);
    $this->postJson('/api/v1/stats-visitor', $payload, apiHeaders($this->token))->assertOk();

    setVisitorProjectContext($this->project);

    $team = $this->project->team;
    $team->update(['events_count_monthly' => 0, 'events_count_overall' => 0]);
    $this->project->update(['events_count_monthly' => 0]);

    StatsVisitor::where('visitor_hash', $payload['visitor_hash'])->firstOrFail()->delete();

    $team->refresh();
    expect($team->events_count_monthly)->toBe(0);
    expect($team->events_count_overall)->toBe(0);
    expect($this->project->fresh()->events_count_monthly)->toBe(0);
});

test('visitors from datacenter IPs are not tracked', function (): void {
    config(['location.datacenter_ip_filter' => true]);

    $position = new Position;
    $position->isHosting = true;
    $position->isProxy = false;
    $position->asn = 16509; // AWS
    Location::fake(['20.42.1.5' => $position]);

    $payload = validVisitorPayload([
        'ip' => '20.42.1.5',
        'visitor_hash' => 'datacenter-hash-'.fake()->uuid(),
    ]);

    $this->postJson('/api/v1/stats-visitor', $payload, apiHeaders($this->token))->assertOk();

    setVisitorProjectContext($this->project);

    expect(StatsVisitor::where('visitor_hash', $payload['visitor_hash'])->exists())->toBeFalse();
});

test('visitors from datacenter IPs flagged as proxy are still not tracked', function (): void {
    config(['location.datacenter_ip_filter' => true]);

    // AWS IP that ip-api also flags as proxy: under the old `hosting && !proxy`
    // rule this leaked through, now the non-VPN ASN keeps it filtered.
    $position = new Position;
    $position->isHosting = true;
    $position->isProxy = true;
    $position->asn = 16509; // AWS
    Location::fake(['20.42.1.6' => $position]);

    $payload = validVisitorPayload([
        'ip' => '20.42.1.6',
        'visitor_hash' => 'datacenter-proxy-hash-'.fake()->uuid(),
    ]);

    $this->postJson('/api/v1/stats-visitor', $payload, apiHeaders($this->token))->assertOk();

    setVisitorProjectContext($this->project);

    expect(StatsVisitor::where('visitor_hash', $payload['visitor_hash'])->exists())->toBeFalse();
});

test('visitors from consumer VPN exits are tracked', function (): void {
    config(['location.datacenter_ip_filter' => true]);

    // Hosted on M247 (AS9009), where consumer VPN providers run their exits.
    $position = new Position;
    $position->isHosting = true;
    $position->isProxy = true;
    $position->asn = 9009;
    Location::fake(['185.220.100.1' => $position]);

    $payload = validVisitorPayload([
        'ip' => '185.220.100.1',
        'visitor_hash' => 'vpn-hash-'.fake()->uuid(),
    ]);

    $this->postJson('/api/v1/stats-visitor', $payload, apiHeaders($this->token))->assertOk();

    setVisitorProjectContext($this->project);

    expect(StatsVisitor::where('visitor_hash', $payload['visitor_hash'])->exists())->toBeTrue();
});

test('visitors from iCloud Private Relay exits are tracked', function (): void {
    config(['location.datacenter_ip_filter' => true]);

    // Private Relay egress on Akamai (AS36183), which ip-api flags as hosting.
    $position = new Position;
    $position->isHosting = true;
    $position->isProxy = false;
    $position->asn = 36183;
    Location::fake(['172.224.226.1' => $position]);

    $payload = validVisitorPayload([
        'ip' => '172.224.226.1',
        'visitor_hash' => 'relay-hash-'.fake()->uuid(),
    ]);

    $this->postJson('/api/v1/stats-visitor', $payload, apiHeaders($this->token))->assertOk();

    setVisitorProjectContext($this->project);

    expect(StatsVisitor::where('visitor_hash', $payload['visitor_hash'])->exists())->toBeTrue();
});

test('visitors are tracked when the hosting flag is unknown', function (): void {
    config(['location.datacenter_ip_filter' => true]);

    Location::fake(['198.51.100.7' => new Position]);

    $payload = validVisitorPayload([
        'ip' => '198.51.100.7',
        'visitor_hash' => 'unknown-flags-hash-'.fake()->uuid(),
    ]);

    $this->postJson('/api/v1/stats-visitor', $payload, apiHeaders($this->token))->assertOk();

    setVisitorProjectContext($this->project);

    expect(StatsVisitor::where('visitor_hash', $payload['visitor_hash'])->exists())->toBeTrue();
});

test('visitors are tracked when the location lookup fails', function (): void {
    config(['location.datacenter_ip_filter' => true]);

    Location::fake([]);

    $payload = validVisitorPayload([
        'ip' => '198.51.100.8',
        'visitor_hash' => 'lookup-failed-hash-'.fake()->uuid(),
    ]);

    $this->postJson('/api/v1/stats-visitor', $payload, apiHeaders($this->token))->assertOk();

    setVisitorProjectContext($this->project);

    expect(StatsVisitor::where('visitor_hash', $payload['visitor_hash'])->exists())->toBeTrue();
});

test('every provider spelling of a country ends up in the same country row', function (): void {
    $ipInfo = new Position;
    $ipInfo->countryCode = 'NL';

    $maxMind = new Position;
    $maxMind->countryName = 'The Netherlands';
    $maxMind->countryCode = 'NL';
    $maxMind->regionName = 'North Holland';
    $maxMind->cityName = 'Amsterdam';

    Location::fake(['198.51.100.20' => $ipInfo, '198.51.100.21' => $maxMind]);

    $hashes = [];

    foreach (['198.51.100.20', '198.51.100.21'] as $ip) {
        $payload = validVisitorPayload(['ip' => $ip, 'visitor_hash' => 'country-'.fake()->uuid()]);
        $this->postJson('/api/v1/stats-visitor', $payload, apiHeaders($this->token))->assertOk();
        $hashes[$ip] = $payload['visitor_hash'];
    }

    setVisitorProjectContext($this->project);

    $withoutNames = StatsVisitor::with(['statsLocationCountry', 'statsLocationRegion', 'statsLocationCity'])->where('visitor_hash', $hashes['198.51.100.20'])->firstOrFail();
    $withNames = StatsVisitor::with('statsLocationCountry')->where('visitor_hash', $hashes['198.51.100.21'])->firstOrFail();

    expect($withoutNames->statsLocationCountry->name)->toBe('Netherlands (NL)')
        ->and($withNames->stats_location_country_id)->toBe($withoutNames->stats_location_country_id)
        ->and($withoutNames->statsLocationRegion)->toBeNull()
        ->and($withoutNames->statsLocationCity)->toBeNull();
});

test('visitor ingest still works while the team owner is soft deleted', function (): void {
    // Inactive owners are soft-deleted for a two month grace window while their
    // team, projects and API tokens keep running, so the event limit check must
    // resolve the plan through billingOwner (SIMPLESTATS-7G).
    $this->project->team->owner->delete();
    auth()->forgetGuards();

    $payload = validVisitorPayload(['visitor_hash' => 'soft-deleted-owner-'.fake()->uuid()]);

    $this->postJson('/api/v1/stats-visitor', $payload, apiHeaders($this->token))->assertOk();

    setVisitorProjectContext($this->project);

    expect(StatsVisitor::where('visitor_hash', $payload['visitor_hash'])->exists())->toBeTrue();
});
