<?php

use App\Enums\McpScope;
use App\Http\Controllers\OAuth\McpOAuthMetadataController;
use App\Mail\TeamInvitation;
use App\Models\Campaign;
use App\Models\Project;
use App\Models\Team;
use App\Models\User;
use App\Services\OAuthKeyService;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use Laravel\Passport\ClientRepository;
use Laravel\Passport\Passport;

const MCP_WRITE_TOOLS = ['create-campaign', 'create-project', 'invite-team-member', 'record-ad-spend', 'update-ad-spend', 'update-campaign', 'update-project', 'update-team-settings'];

const MCP_USER_TOOLS = ['create-project', 'get-team', 'invite-team-member', 'list-projects', 'list-team-members', 'update-project', 'update-team-settings'];

beforeEach(function (): void {
    $this->owner = User::factory()->withPersonalTeam()->create();
    $this->team = $this->owner->currentTeam;
    $this->team->update(['currency' => 'EUR', 'timezone' => 'Europe/Berlin', 'events_count_monthly' => 1234]);

    $this->shop = Project::factory()->create(['team_id' => $this->team->id, 'name' => 'Shop', 'domain' => 'shop.example.com']);
    $this->blog = Project::factory()->create(['team_id' => $this->team->id, 'name' => 'Blog', 'domain' => 'blog.example.com']);

    $this->foreignTeam = User::factory()->withPersonalTeam()->create()->currentTeam;
    $this->foreignProject = Project::factory()->create(['team_id' => $this->foreignTeam->id, 'name' => 'Foreign Shop']);
});

function teamMember(Team $team, string $role, ?array $projectIds = null): User
{
    $member = User::factory()->create();
    $team->users()->attach($member, ['role' => $role, 'project_ids' => $projectIds]);
    $member->switchTeam($team);

    return $member->fresh();
}

function actAsOAuthUser(User $user, array $scopes = [McpScope::READ->value, McpScope::WRITE->value]): User
{
    return Passport::actingAs($user, $scopes);
}

function mcpPost(array $payload, ?string $bearer = null): TestResponse
{
    return test()->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, ...$payload], array_filter([
        'Accept' => 'application/json, text/event-stream',
        'Authorization' => $bearer ? 'Bearer '.$bearer : null,
    ]));
}

function oauthTool(string $tool, array $arguments = [], ?string $bearer = null): TestResponse
{
    return mcpPost(['method' => 'tools/call', 'params' => ['name' => $tool, 'arguments' => $arguments]], $bearer)->assertOk();
}

/**
 * @return array<int, string>
 */
function listedTools(?string $bearer = null): array
{
    return collect(mcpPost(['method' => 'tools/list', 'params' => []], $bearer)->assertOk()->json('result.tools'))
        ->pluck('name')
        ->sort()
        ->values()
        ->all();
}

function temporaryPassportKeyPath(): string
{
    $keyPath = sys_get_temp_dir().'/simplestats-passport-keys-'.Str::random(8);
    File::ensureDirectoryExists($keyPath);
    Passport::loadKeysFrom($keyPath);

    return $keyPath;
}

it('advertises the read and write scope for discovery and client registration', function (): void {
    $this->getJson('/.well-known/oauth-protected-resource/mcp')
        ->assertOk()
        ->assertJsonPath('scopes_supported', ['mcp:use', 'mcp:write']);

    $this->getJson('/.well-known/oauth-authorization-server')
        ->assertOk()
        ->assertJsonPath('scopes_supported', ['mcp:use', 'mcp:write'])
        ->assertJsonPath('authorization_endpoint', route('passport.authorizations.authorize'));

    $this->postJson('/mcp/oauth/register', ['client_name' => 'Claude Code', 'redirect_uris' => ['http://localhost:4567/callback']])
        ->assertCreated()
        ->assertJsonPath('scope', 'mcp:use mcp:write');
});

it('registers the discovery routes as controller actions so route:cache keeps the write scope', function (string $routeName): void {
    expect(Route::getRoutes()->getByName($routeName)->getControllerClass())->toBe(McpOAuthMetadataController::class);
})->with([
    'mcp.oauth.protected-resource',
    'mcp.oauth.protected-resource.nested',
    'mcp.oauth.authorization-server',
    'mcp.oauth.authorization-server.nested',
]);

it('points unauthenticated clients to the oauth metadata', function (): void {
    mcpPost(['method' => 'initialize'])
        ->assertUnauthorized()
        ->assertHeader('WWW-Authenticate', 'Bearer realm="mcp", resource_metadata="'.url('/.well-known/oauth-protected-resource/mcp').'"');
});

it('completes the oauth flow with write access only when the user opts in on the consent screen', function (array $approval, bool $canWrite): void {
    $client = resolve(ClientRepository::class)->createAuthorizationCodeGrantClient('Claude Code', ['http://localhost:4567/callback'], confidential: false);
    $verifier = Str::random(64);
    $challenge = strtr(rtrim(base64_encode(hash('sha256', $verifier, true)), '='), '+/', '-_');

    $this->actingAs($this->owner)
        ->get('/mcp/oauth/authorize?'.http_build_query([
            'client_id' => $client->getKey(),
            'redirect_uri' => 'http://localhost:4567/callback',
            'response_type' => 'code',
            'scope' => 'mcp:use mcp:write',
            'state' => 'xyz',
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
        ]))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('OAuth/Authorize')
            ->where('client.name', 'Claude Code')
            ->where('client.redirect_host', 'localhost')
            ->where('requestsWrite', true)
            ->has('scopes', 2));

    $redirect = $this->post('/mcp/oauth/authorize', [
        'state' => '',
        'client_id' => $client->getKey(),
        'auth_token' => session('authToken'),
        ...$approval,
    ])->assertRedirect()->headers->get('Location');

    parse_str((string) parse_url($redirect, PHP_URL_QUERY), $query);

    $token = $this->postJson('/mcp/oauth/token', [
        'grant_type' => 'authorization_code',
        'client_id' => $client->getKey(),
        'redirect_uri' => 'http://localhost:4567/callback',
        'code_verifier' => $verifier,
        'code' => $query['code'],
    ])->assertOk()->json();

    auth()->forgetGuards();

    $tools = listedTools($token['access_token']);

    expect($tools)->toContain('list-projects', 'get-team', 'list-campaigns');

    $canWrite
        ? expect($tools)->toContain(...MCP_WRITE_TOOLS)
        : expect($tools)->not->toContain(...MCP_WRITE_TOOLS);

    oauthTool('list-projects', bearer: $token['access_token'])
        ->assertJsonPath('result.structuredContent.team', $this->team->name);
})->with([
    'read-only by default' => [[], false],
    'write when opted in' => [['allow_write' => '1'], true],
]);

it('shows the redirect host of the pending request on the consent screen', function (): void {
    $client = resolve(ClientRepository::class)->createAuthorizationCodeGrantClient('Claude', ['https://claude.ai/callback', 'https://evil.example/callback'], confidential: false);

    $this->actingAs($this->owner)
        ->get('/mcp/oauth/authorize?'.http_build_query([
            'client_id' => $client->getKey(),
            'redirect_uri' => 'https://evil.example/callback',
            'response_type' => 'code',
            'scope' => 'mcp:use',
            'state' => 'xyz',
            'code_challenge' => strtr(rtrim(base64_encode(hash('sha256', Str::random(64), true)), '='), '+/', '-_'),
            'code_challenge_method' => 'S256',
        ]))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page->where('client.redirect_host', 'evil.example'));
});

it('refuses oauth tokens without the mcp scope', function (): void {
    actAsOAuthUser($this->owner, []);

    mcpPost(['method' => 'initialize'])->assertForbidden();
});

it('answers an invalid bearer token with a clean 401', function (): void {
    Exceptions::fake();

    mcpPost(['method' => 'initialize'], 'eyJhbGciOiJSUzI1NiJ9.invalid.token')->assertUnauthorized();

    Exceptions::assertNothingReported();
});

it('lets the write scope imply read access', function (): void {
    actAsOAuthUser($this->owner, [McpScope::WRITE->value]);

    expect(listedTools())->toContain('list-projects', 'create-project');
});

it('refuses oauth users with an unverified email address', function (): void {
    $this->owner->forceFill(['email_verified_at' => null])->save();
    actAsOAuthUser($this->owner);

    mcpPost(['method' => 'initialize'])->assertForbidden()->assertJsonPath('message', 'Verify your email address in SimpleStats before connecting an AI client.');
});

it('offers every tool to an oauth connection with write access', function (): void {
    actAsOAuthUser($this->owner);

    expect(listedTools())->toContain(...MCP_USER_TOOLS, ...MCP_WRITE_TOOLS);
});

it('refuses every write with a read-only oauth token', function (string $tool, array $arguments): void {
    actAsOAuthUser($this->owner, [McpScope::READ->value]);
    $arguments = array_map(fn (mixed $value): mixed => $value === 'SHOP' ? $this->shop->id : $value, $arguments);

    expect(listedTools())->not->toContain(...MCP_WRITE_TOOLS);

    mcpPost(['method' => 'tools/call', 'params' => ['name' => $tool, 'arguments' => $arguments]])
        ->assertJsonPath('error.message', "Tool [{$tool}] not found.");

    expect(Project::withoutGlobalScopes()->count())->toBe(3)
        ->and($this->shop->fresh()->name)->toBe('Shop')
        ->and($this->team->fresh()->name)->not->toBe('Hijacked')
        ->and(Campaign::withoutGlobalScopes()->count())->toBe(0);
})->with([
    'create project' => ['create-project', ['name' => 'New', 'domain' => 'new.example.com', 'usages' => ['visitors']]],
    'update project' => ['update-project', ['project_id' => 'SHOP', 'name' => 'Hijacked']],
    'update team' => ['update-team-settings', ['name' => 'Hijacked']],
    'invite member' => ['invite-team-member', ['email' => 'x@example.com', 'role' => 'viewer']],
    'create campaign' => ['create-campaign', ['name' => 'Sneaky', 'project_id' => 'SHOP']],
]);

it('creates a project and returns its api token exactly once', function (): void {
    actAsOAuthUser($this->owner);

    $response = oauthTool('create-project', [
        'name' => 'Landing',
        'domain' => 'https://www.landing.example.com/pricing',
        'usages' => ['users', 'payments', 'subscription'],
        'flags' => ['paid_only'],
    ]);

    $project = Project::withoutGlobalScopes()->where('name', 'Landing')->sole();
    $plainToken = $project->tokens()->sole()->token_plain_part;

    $response->assertJsonPath('result.structuredContent.project.project_id', $project->id)
        ->assertJsonPath('result.structuredContent.project.domain', 'www.landing.example.com')
        ->assertJsonPath('result.structuredContent.project.usages', ['visitors', 'users', 'payments', 'subscription'])
        ->assertJsonPath('result.structuredContent.project.flags', ['paid_only'])
        ->assertJsonPath('result.structuredContent.api_token', $plainToken);

    expect($project->team_id)->toBe($this->team->id)
        ->and($project->usage)->toBe(Project::USAGE_VISITORS | Project::USAGE_USERS | Project::USAGE_PAYMENTS | Project::USAGE_SUBSCRIPTION);

    oauthTool('list-projects')->assertSee('Landing')->assertDontSee($plainToken);
    oauthTool('update-project', ['project_id' => $project->id, 'name' => 'Landing 2'])->assertDontSee($plainToken);
});

it('rejects invalid project input with the web form rules', function (array $arguments, string $message): void {
    actAsOAuthUser($this->owner);

    oauthTool('create-project', ['name' => 'Landing', 'domain' => 'landing.example.com', ...$arguments])
        ->assertJsonPath('result.isError', true)
        ->assertSee($message);

    expect(Project::withoutGlobalScopes()->where('name', 'Landing')->exists())->toBeFalse();
})->with([
    'subscription without payments' => [['usages' => ['subscription']], 'Subscription tracking requires both Registrations and Payments.'],
    'paid only without payments' => [['usages' => ['users'], 'flags' => ['paid_only']], 'requires both Registrations and Payments'],
    'unknown usage' => [['usages' => ['events']], 'The selected usages.0 is invalid.'],
    'invalid domain' => [['usages' => ['visitors'], 'domain' => 'not a domain'], 'The domain field format is invalid.'],
]);

it('updates only the given project fields and keeps the others', function (): void {
    actAsOAuthUser($this->owner);
    $this->shop->update(['usage' => Project::USAGE_VISITORS | Project::USAGE_USERS | Project::USAGE_PAYMENTS]);

    oauthTool('update-project', ['project_id' => $this->shop->id, 'name' => 'Shop 2026'])
        ->assertJsonPath('result.structuredContent.project.name', 'Shop 2026')
        ->assertJsonPath('result.structuredContent.project.usages', ['visitors', 'users', 'payments']);

    expect($this->shop->fresh())
        ->name->toBe('Shop 2026')
        ->domain->toBe('shop.example.com')
        ->usage->toBe(Project::USAGE_VISITORS | Project::USAGE_USERS | Project::USAGE_PAYMENTS);
});

it('enforces the plan project limit on create', function (): void {
    $this->skipUnlessSaas();

    // Outside production sparkPlan() falls back to the highest configured
    // plan, so shrinking the list to the Free plan (1 project) simulates a free user.
    config()->set('spark.billables.user.plans', [config('spark.billables.user.plans')[0]]);
    actAsOAuthUser($this->owner);

    oauthTool('create-project', ['name' => 'Landing', 'domain' => 'landing.example.com', 'usages' => ['visitors']])
        ->assertJsonPath('result.isError', true)
        ->assertSee('reached the project limit of its plan');

    oauthTool('get-team')
        ->assertJsonPath('result.structuredContent.team.projects', ['count' => 2, 'limit' => 1]);

    expect(Project::withoutGlobalScopes()->where('name', 'Landing')->exists())->toBeFalse();
});

it('reports the team with plan and event usage', function (): void {
    actAsOAuthUser($this->owner);

    oauthTool('get-team')
        ->assertJsonPath('result.structuredContent.team.team_id', $this->team->uuid)
        ->assertJsonPath('result.structuredContent.team.currency', 'EUR')
        ->assertJsonPath('result.structuredContent.team.timezone', 'Europe/Berlin')
        ->assertJsonPath('result.structuredContent.team.your_role', 'owner')
        ->assertJsonPath('result.structuredContent.team.events_this_month.used', 1234)
        ->assertJsonMissingPath('result.structuredContent.team.id');
});

it('updates the team settings as owner or administrator', function (string $role): void {
    $user = $role === 'owner' ? $this->owner : teamMember($this->team, 'admin');
    actAsOAuthUser($user);

    oauthTool('update-team-settings', ['currency' => 'usd', 'timezone' => 'America/New_York'])
        ->assertJsonPath('result.structuredContent.team.currency', 'USD');

    expect($this->team->fresh())
        ->currency->toBe('USD')
        ->timezone->toBe('America/New_York')
        ->name->toBe($this->team->name);
})->with(['owner', 'admin']);

it('validates the team settings like the team page', function (): void {
    actAsOAuthUser($this->owner);

    oauthTool('update-team-settings', ['timezone' => 'Mars/Olympus'])
        ->assertJsonPath('result.isError', true)
        ->assertSee('The selected timezone is invalid.');

    expect($this->team->fresh()->timezone)->toBe('Europe/Berlin');
});

it('invites editors and viewers with the normal invitation mail', function (): void {
    Mail::fake();
    actAsOAuthUser($this->owner);

    oauthTool('invite-team-member', ['email' => 'client@example.com', 'role' => 'viewer', 'project_ids' => [$this->shop->id]])
        ->assertJsonPath('result.structuredContent.projects', [['project_id' => $this->shop->id, 'name' => 'Shop']]);

    $invitation = $this->team->teamInvitations()->sole();

    expect($invitation)
        ->email->toBe('client@example.com')
        ->role->toBe('viewer')
        ->project_ids->toBe([$this->shop->id])
        ->and($this->team->users()->where('email', 'client@example.com')->exists())->toBeFalse();

    Mail::assertQueued(TeamInvitation::class, fn (TeamInvitation $mail): bool => $mail->hasTo('client@example.com'));

    oauthTool('list-team-members')
        ->assertJsonPath('result.structuredContent.pending_invitations.0.email', 'client@example.com');
});

it('never invites administrators or unknown roles', function (string $role): void {
    Mail::fake();
    actAsOAuthUser($this->owner);

    oauthTool('invite-team-member', ['email' => 'boss@example.com', 'role' => $role])
        ->assertJsonPath('result.isError', true)
        ->assertSee('The role must be editor or viewer.');

    expect($this->team->teamInvitations()->exists())->toBeFalse();
    Mail::assertNothingQueued();
})->with(['admin', 'owner', 'superuser']);

it('refuses to invite with projects of another team', function (): void {
    Mail::fake();
    actAsOAuthUser($this->owner);

    oauthTool('invite-team-member', ['email' => 'client@example.com', 'role' => 'editor', 'project_ids' => [$this->foreignProject->id]])
        ->assertJsonPath('result.isError', true)
        ->assertSee('does not belong to this team');

    expect($this->team->teamInvitations()->exists())->toBeFalse();
});

it('applies the team role to every user tool', function (string $role, array $allowed): void {
    Mail::fake();
    actAsOAuthUser(teamMember($this->team, $role));

    $calls = [
        'list-projects' => [],
        'get-team' => [],
        'list-team-members' => [],
        'create-project' => ['name' => 'New', 'domain' => 'new.example.com', 'usages' => ['visitors']],
        'update-project' => ['project_id' => $this->shop->id, 'name' => 'Renamed'],
        'update-team-settings' => ['name' => 'Renamed Team'],
        'invite-team-member' => ['email' => 'friend@example.com', 'role' => 'viewer'],
    ];

    foreach ($calls as $tool => $arguments) {
        $isError = oauthTool($tool, $arguments)->json('result.isError') === true;

        expect($isError)->toBe(! in_array($tool, $allowed, true), "{$role} calling {$tool}");
    }
})->with([
    'viewer' => ['viewer', ['list-projects', 'get-team', 'list-team-members']],
    'editor' => ['editor', ['list-projects', 'get-team', 'list-team-members', 'create-project', 'update-project']],
    'admin' => ['admin', ['list-projects', 'get-team', 'list-team-members', 'create-project', 'update-project', 'update-team-settings', 'invite-team-member']],
]);

it('limits a member with project access to their projects and to themselves', function (): void {
    $member = teamMember($this->team, 'editor', [$this->shop->id]);
    teamMember($this->team, 'viewer');
    actAsOAuthUser($member);

    oauthTool('list-projects')
        ->assertJsonPath('result.structuredContent.projects.0.name', 'Shop')
        ->assertJsonCount(1, 'result.structuredContent.projects')
        ->assertJsonPath('result.structuredContent.can_create_projects', false);

    oauthTool('update-project', ['project_id' => $this->blog->id, 'name' => 'Hijacked'])
        ->assertJsonPath('result.isError', true)
        ->assertSee('No accessible project with this project_id exists.');

    oauthTool('create-project', ['name' => 'New', 'domain' => 'new.example.com', 'usages' => ['visitors']])
        ->assertJsonPath('result.isError', true);

    oauthTool('list-team-members')
        ->assertJsonCount(1, 'result.structuredContent.members')
        ->assertJsonPath('result.structuredContent.members.0.user_id', $member->uuid)
        ->assertJsonPath('result.structuredContent.members.0.projects', [['project_id' => $this->shop->id, 'name' => 'Shop']])
        ->assertJsonPath('result.structuredContent.pending_invitations', null);

    oauthTool('get-team')->assertJsonPath('result.structuredContent.team.events_this_month', null);

    expect($this->blog->fresh()->name)->toBe('Blog')
        ->and(Project::withoutGlobalScopes()->where('name', 'New')->exists())->toBeFalse();
});

it('lists the whole team with uuids for an unrestricted member', function (): void {
    $editor = teamMember($this->team, 'editor');
    actAsOAuthUser($editor);

    $members = oauthTool('list-team-members')->json('result.structuredContent.members');

    expect(collect($members)->pluck('role')->all())->toBe(['owner', 'editor'])
        ->and(collect($members)->pluck('user_id')->every(fn (string $id): bool => Str::isUuid($id)))->toBeTrue()
        ->and(collect($members)->firstWhere('role', 'owner')['email'])->toBe($this->owner->email);
});

it('keeps oauth users inside their current team', function (): void {
    actAsOAuthUser($this->owner);

    oauthTool('list-projects')->assertDontSee('Foreign Shop');
    oauthTool('list-team-members')->assertDontSee($this->foreignTeam->owner->email);

    oauthTool('update-project', ['project_id' => $this->foreignProject->id, 'name' => 'Hijacked'])
        ->assertJsonPath('result.isError', true)
        ->assertSee('No accessible project with this project_id exists.');

    oauthTool('list-campaigns', ['project_id' => $this->foreignProject->id])
        ->assertJsonPath('result.structuredContent.campaigns', []);

    expect($this->foreignProject->fresh()->name)->toBe('Foreign Shop');
});

it('runs the campaign tools through the user policies over oauth', function (): void {
    $this->shop->update(['usage' => Project::USAGE_VISITORS | Project::USAGE_USERS | Project::USAGE_PAYMENTS]);

    actAsOAuthUser(teamMember($this->team, 'viewer'));
    oauthTool('create-campaign', ['name' => 'Black Week', 'project_id' => $this->shop->id])
        ->assertJsonPath('result.isError', true)
        ->assertSee('does not allow creating campaigns');

    actAsOAuthUser(teamMember($this->team, 'editor'));
    oauthTool('create-campaign', ['name' => 'Black Week', 'project_id' => $this->shop->id])
        ->assertJsonPath('result.structuredContent.campaign.project', 'Shop');

    oauthTool('create-campaign', ['name' => 'Sneaky', 'project_id' => $this->foreignProject->id])
        ->assertJsonPath('result.isError', true);

    expect(Campaign::withoutGlobalScopes()->pluck('name')->all())->toBe(['Black Week']);
});

it('creates the oauth key pair once when it is missing', function (): void {
    $keyPath = temporaryPassportKeyPath();

    resolve(OAuthKeyService::class)->ensureKeys();
    $privateKey = File::get("{$keyPath}/oauth-private.key");

    resolve(OAuthKeyService::class)->ensureKeys();

    expect(File::get("{$keyPath}/oauth-private.key"))->toBe($privateKey)
        ->and($privateKey)->toContain('PRIVATE KEY')
        ->and(File::get("{$keyPath}/oauth-public.key"))->toContain('PUBLIC KEY')
        ->and(decoct(fileperms("{$keyPath}/oauth-private.key") & 0777))->toBe('600');

    File::deleteDirectory($keyPath);
});

it('leaves configured keys alone', function (): void {
    $keyPath = temporaryPassportKeyPath();
    config(['passport.private_key' => 'private', 'passport.public_key' => 'public']);

    resolve(OAuthKeyService::class)->ensureKeys();

    expect(File::exists("{$keyPath}/oauth-private.key"))->toBeFalse();

    File::deleteDirectory($keyPath);
});
