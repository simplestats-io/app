<?php

use App\Http\Controllers\GscConnectionController;
use App\Jobs\SyncGscDataJob;
use App\Models\GscConnection;
use App\Models\Project;
use App\Models\Team;
use App\Models\User;
use App\Services\Gsc\GscApiService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\GoogleProvider;
use Laravel\Socialite\Two\User as GoogleUser;

beforeEach(function (): void {
    config(['services.google_search_console.client_id' => 'client-id', 'services.google_search_console.client_secret' => 'client-secret']);

    $this->owner = User::factory()->withPersonalTeam()->create();
    $this->team = $this->owner->currentTeam;
    $this->project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->actingAs($this->owner);
});

function gscFakeGoogleUser(array $scopes = [GscApiService::SCOPE]): void
{
    $googleUser = (new GoogleUser)
        ->map(['id' => 'g-1', 'email' => 'webmaster@example.com'])
        ->setToken('access-token')
        ->setRefreshToken('refresh-token')
        ->setExpiresIn(3600)
        ->setApprovedScopes($scopes);

    $provider = Mockery::mock(GoogleProvider::class);
    $provider->shouldReceive('user')->andReturn($googleUser);

    Socialite::shouldReceive('buildProvider')->with(GoogleProvider::class, Mockery::any())->andReturn($provider);
}

function gscFakeSites(array $sites): void
{
    Http::fake([
        'searchconsole.googleapis.com/webmasters/v3/sites' => Http::response(['siteEntry' => array_map(
            fn (string $site): array => ['siteUrl' => $site, 'permissionLevel' => 'siteOwner'],
            $sites,
        )]),
        'oauth2.googleapis.com/revoke' => Http::response(),
    ]);
}

it('redirects to Google with the read-only Search Console scope', function (): void {
    $this->get(route('gsc-connections.create', $this->project))
        ->assertRedirectContains('accounts.google.com')
        ->assertRedirectContains(urlencode(GscApiService::SCOPE))
        ->assertRedirectContains('access_type=offline')
        ->assertSessionHas(GscConnectionController::SESSION_KEY, $this->project->uuid);
});

it('hides the connection when no Google client is configured', function (): void {
    config(['services.google_search_console.client_id' => null]);

    $this->get(route('gsc-connections.create', $this->project))->assertNotFound();
});

it('lets viewers neither connect nor disconnect', function (): void {
    $viewer = User::factory()->create();
    $this->team->users()->attach($viewer, ['role' => 'viewer']);
    $viewer->switchTeam($this->team);
    GscConnection::factory()->create(['project_id' => $this->project->id]);

    $this->actingAs($viewer)->get(route('gsc-connections.create', $this->project))->assertForbidden();
    $this->actingAs($viewer)->delete(route('gsc-connections.destroy', $this->project))->assertForbidden();
});

it('does not resolve projects of other teams', function (): void {
    $foreign = Project::withoutEvents(fn (): Project => Project::factory()->create([
        'team_id' => Team::withoutGlobalScopes()->where('user_id', User::factory()->withPersonalTeam()->create()->id)->value('id'),
    ]));

    $this->get(route('gsc-connections.create', $foreign))->assertNotFound();
});

it('stores the connection of the project from the session on the callback', function (): void {
    gscFakeGoogleUser();

    $this->withSession([GscConnectionController::SESSION_KEY => $this->project->uuid])
        ->get(route('gsc-connections.store', ['code' => 'abc', 'state' => 'xyz']))
        ->assertRedirect(route('projects.show', $this->project));

    $connection = $this->project->gscConnection()->firstOrFail();

    expect($connection->google_email)->toBe('webmaster@example.com')
        ->and($connection->refresh_token)->toBe('refresh-token')
        ->and($connection->site_url)->toBeNull()
        ->and(DB::table('gsc_connections')->value('access_token'))->not->toBe('access-token');
});

it('does not connect when the Search Console scope was not granted', function (): void {
    gscFakeGoogleUser(scopes: ['openid', 'email']);

    $this->withSession([GscConnectionController::SESSION_KEY => $this->project->uuid])
        ->get(route('gsc-connections.store', ['code' => 'abc', 'state' => 'xyz']));

    expect($this->project->gscConnection()->exists())->toBeFalse();
});

it('resumes syncing after reconnecting a flagged connection', function (): void {
    Bus::fake();
    gscFakeGoogleUser();
    GscConnection::factory()->create([
        'project_id' => $this->project->id,
        'google_email' => 'webmaster@example.com',
        'reconnect_required_at' => now(),
    ]);

    $this->withSession([GscConnectionController::SESSION_KEY => $this->project->uuid])
        ->get(route('gsc-connections.store', ['code' => 'abc', 'state' => 'xyz']));

    expect($this->project->gscConnection->reconnect_required_at)->toBeNull()
        ->and($this->project->gscConnection->site_url)->toBe('sc-domain:example.com');
    Bus::assertDispatched(SyncGscDataJob::class);
});

it('links a property of the Google account and queues the backfill', function (): void {
    Bus::fake();
    gscFakeSites(['sc-domain:example.com', 'https://shop.example.com/']);
    GscConnection::factory()->create(['project_id' => $this->project->id, 'site_url' => null, 'user_id' => $this->owner->id]);

    $this->put(route('gsc-connections.update', $this->project), ['site_url' => 'https://shop.example.com/'])
        ->assertSessionHasNoErrors();

    expect($this->project->gscConnection->site_url)->toBe('https://shop.example.com/');
    Bus::assertDispatched(SyncGscDataJob::class);
});

it('rejects a property the Google account cannot read', function (): void {
    Bus::fake();
    gscFakeSites(['sc-domain:example.com']);
    GscConnection::factory()->create(['project_id' => $this->project->id, 'site_url' => null, 'user_id' => $this->owner->id]);

    $this->put(route('gsc-connections.update', $this->project), ['site_url' => 'sc-domain:competitor.com'])
        ->assertSessionHasErrors('site_url');

    Bus::assertNothingDispatched();
});

it('disconnects, revokes the grant and removes the keyword data', function (): void {
    gscFakeSites([]);
    GscConnection::factory()->create(['project_id' => $this->project->id]);
    DB::table('stats_keyword_daily')->insert([
        'team_id' => $this->team->id, 'project_id' => $this->project->id, 'date' => '2026-11-01',
        'stats_track_keyword_id' => DB::table('stats_track_keywords')->insertGetId(['team_id' => $this->team->id, 'name' => 'k']),
        'stats_page_id' => DB::table('stats_pages')->insertGetId(['team_id' => $this->team->id, 'name' => '/']),
        'clicks' => 1, 'impressions' => 1, 'position' => 1,
    ]);

    $this->delete(route('gsc-connections.destroy', $this->project))->assertRedirect();

    expect($this->project->gscConnection()->exists())->toBeFalse()
        ->and(DB::table('stats_keyword_daily')->count())->toBe(0);
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'oauth2.googleapis.com/revoke'));
});

it('lets only the person who connected Google pick a property', function (): void {
    Bus::fake();
    gscFakeSites(['sc-domain:example.com', 'sc-domain:other-client.com']);
    GscConnection::factory()->create(['project_id' => $this->project->id, 'site_url' => null, 'user_id' => $this->owner->id]);

    $editor = User::factory()->create();
    $this->team->users()->attach($editor, ['role' => 'editor', 'project_ids' => [$this->project->id]]);
    $editor->switchTeam($this->team);

    $this->actingAs($editor)
        ->put(route('gsc-connections.update', $this->project), ['site_url' => 'sc-domain:other-client.com'])
        ->assertForbidden();

    expect($this->project->gscConnection->site_url)->toBeNull();
    Bus::assertNotDispatched(SyncGscDataJob::class);
});

it('keeps the Google grant when another project still uses the same account', function (): void {
    gscFakeSites([]);
    GscConnection::factory()->create(['project_id' => $this->project->id, 'google_email' => 'webmaster@example.com']);
    GscConnection::factory()->create([
        'project_id' => Project::factory()->create(['team_id' => $this->team->id])->id,
        'google_email' => 'webmaster@example.com',
    ]);

    $this->delete(route('gsc-connections.destroy', $this->project))->assertRedirect();

    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'revoke'));
});
