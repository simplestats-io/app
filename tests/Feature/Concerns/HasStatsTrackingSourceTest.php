<?php

use App\Models\Project;
use App\Models\StatsVisitor;
use App\Models\Team;
use Illuminate\Support\Facades\Context;

beforeEach(function (): void {
    $this->team = Team::factory()->create(['timezone' => 'UTC']);
    $this->project = Project::factory()->create([
        'team_id' => $this->team->id,
        'domain' => 'my-app.test',
    ]);

    // Simulate the ingest context: the authenticated "user" is the project itself.
    auth()->setUser($this->project);
    Context::add('team_id', $this->team->id);
    Context::add('project_id', $this->project->id);
});

/**
 * Create a visitor and return it with the resolved source/referer lookup relations
 * loaded. track_source/track_referer are normalised into lookup tables on save, so
 * the stored value is read back via the relation name rather than the raw column.
 */
function makeVisitor(array $attributes): StatsVisitor
{
    $visitor = StatsVisitor::create(array_merge([
        'team_id' => test()->team->id,
        'project_id' => test()->project->id,
        'visitor_hash' => 'hash-'.fake()->uuid(),
        'time' => now()->format('Y-m-d H:i:s'),
    ], $attributes));

    return $visitor->load('statsTrackSource', 'statsTrackReferer');
}

test('the project own domain sent as a source is dropped, not stored as a referer', function (): void {
    $visitor = makeVisitor(['track_source' => 'my-app.test']);

    expect($visitor->statsTrackSource)->toBeNull()
        ->and($visitor->statsTrackReferer)->toBeNull();
});

test('the project own domain as a full url source is dropped', function (): void {
    $visitor = makeVisitor(['track_source' => 'https://www.my-app.test/dashboard']);

    expect($visitor->statsTrackSource)->toBeNull()
        ->and($visitor->statsTrackReferer)->toBeNull();
});

test('the project own domain sent as a referer is dropped', function (): void {
    $visitor = makeVisitor(['track_referer' => 'https://my-app.test/login']);

    expect($visitor->statsTrackReferer)->toBeNull();
});

test('a subdomain of the project own domain is kept as a referer (separate property)', function (): void {
    $visitor = makeVisitor(['track_referer' => 'https://account.my-app.test/login']);

    expect($visitor->statsTrackReferer?->name)->toBe('account.my-app.test');
});

test('an unrelated domain that merely shares a substring is kept', function (): void {
    // "app.test" is a substring of the project domain "my-app.test" but a different
    // host, so it must be tracked as a normal external referer.
    $visitor = makeVisitor(['track_referer' => 'https://app.test']);

    expect($visitor->statsTrackReferer?->name)->toBe('app.test');
});

test('an external domain sent as a source is still converted to a referer (backwards compat)', function (): void {
    $visitor = makeVisitor(['track_source' => 'google.com']);

    expect($visitor->statsTrackSource)->toBeNull()
        ->and($visitor->statsTrackReferer?->name)->toBe('google.com');
});

test('a plain word source is kept as a source', function (): void {
    $visitor = makeVisitor(['track_source' => 'newsletter']);

    expect($visitor->statsTrackSource?->name)->toBe('newsletter')
        ->and($visitor->statsTrackReferer)->toBeNull();
});

test('an external referer is kept', function (): void {
    $visitor = makeVisitor(['track_referer' => 'https://news.ycombinator.com/']);

    expect($visitor->statsTrackReferer?->name)->toBe('news.ycombinator.com');
});

test('a www. inside the host does not make a foreign domain look like the own one', function (): void {
    $visitor = makeVisitor(['track_referer' => 'https://my-www.app.test']);

    expect($visitor->statsTrackReferer?->name)->toBe('my-www.app.test');
});
