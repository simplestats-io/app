<?php

use App\Enums\McpScope;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;

beforeEach(function (): void {
    $this->seed();
    $this->user = User::where('email', config('app.test.user')['email'])->firstOrFail();
    $this->project = Project::first();

    // TestUserSeeder leaves the test user on the web guard and its team in the
    // context; the endpoint must resolve both from the bearer token alone.
    auth()->forgetGuards();
    Context::forget('team_id');
});

function actAsMcpUser(User $user): void
{
    Passport::actingAs($user, [McpScope::READ->value, McpScope::WRITE->value]);
}

function mcpRequest(string $method, array $params = [], int $id = 1): array
{
    return [
        'jsonrpc' => '2.0',
        'id' => $id,
        'method' => $method,
        'params' => $params,
    ];
}

function mcpHeaders(?string $token): array
{
    return array_filter([
        'Accept' => 'application/json, text/event-stream',
        'Authorization' => $token ? 'Bearer '.$token : null,
    ]);
}

it('rejects unauthenticated requests', function (): void {
    $this->postJson('/mcp', mcpRequest('initialize'), mcpHeaders(null))
        ->assertUnauthorized();
});

it('rejects project api tokens with a hint to connect via oauth', function (): void {
    Exceptions::fake();
    $token = $this->project->createToken('mcp-test-token')->plainTextToken;

    $this->postJson('/mcp', mcpRequest('initialize'), mcpHeaders($token))
        ->assertUnauthorized()
        ->assertHeader('WWW-Authenticate', 'Bearer realm="mcp", resource_metadata="'.url('/.well-known/oauth-protected-resource/mcp').'"')
        ->assertJsonPath('message', fn (string $message): bool => str_contains($message, 'requires an OAuth connection') && str_contains($message, '/docs/mcp-server.html'));

    $this->postJson('/mcp', mcpRequest('tools/call', ['name' => 'list-stats-capabilities', 'arguments' => []], 2), mcpHeaders($token))
        ->assertUnauthorized()
        ->assertDontSee($this->project->name);

    Exceptions::assertNothingReported();
});

it('initializes over oauth', function (): void {
    actAsMcpUser($this->user);

    $response = $this->postJson('/mcp', mcpRequest('initialize', [
        'protocolVersion' => '2025-03-26',
        'capabilities' => [],
        'clientInfo' => ['name' => 'pest', 'version' => '1.0'],
    ]), mcpHeaders(null));

    $response->assertOk();
    expect($response->getContent())->toContain('SimpleStats');
});

it('serves team-scoped kpi stats defaulting to all projects', function (): void {
    DB::table('stats_visitors')->delete();

    $time = now()->subDay()->format('Y-m-d').' 12:00:00';

    for ($i = 0; $i < 3; $i++) {
        DB::table('stats_visitors')->insert([
            'team_id' => $this->project->team_id,
            'project_id' => $this->project->id,
            'visitor_hash' => Str::uuid()->toString(),
            'time' => $time,
            'time_utc' => $time,
        ]);
    }

    actAsMcpUser($this->user);

    $response = $this->postJson('/mcp', mcpRequest('tools/call', [
        'name' => 'get-kpi-stats',
        'arguments' => ['preset' => 'last_7_days'],
    ], 2), mcpHeaders(null));

    $response->assertOk();

    expect($response->getContent())->toContain('All projects');
});

it('lists the projects of the current team only as capability', function (): void {
    $foreignTeam = User::factory()->withPersonalTeam()->create()->currentTeam;
    Project::factory()->create(['team_id' => $foreignTeam->id, 'name' => 'Foreign Client']);

    actAsMcpUser($this->user);

    $response = $this->postJson('/mcp', mcpRequest('tools/call', [
        'name' => 'list-stats-capabilities',
        'arguments' => [],
    ], 2), mcpHeaders(null));

    $response->assertOk();

    expect($response->getContent())
        ->toContain($this->project->name)
        ->toContain('All projects')
        ->not->toContain('Foreign Client');
});

it('serves clients on the 2026-07-28 protocol revision', function (): void {
    $meta = [
        'io.modelcontextprotocol/protocolVersion' => '2026-07-28',
        'io.modelcontextprotocol/clientCapabilities' => [],
    ];

    actAsMcpUser($this->user);

    $this->postJson('/mcp', mcpRequest('server/discover', ['_meta' => $meta]), [
        ...mcpHeaders(null),
        'MCP-Protocol-Version' => '2026-07-28',
        'Mcp-Method' => 'server/discover',
    ])->assertOk()->assertJsonPath('result.supportedVersions', ['2026-07-28']);

    $response = $this->postJson('/mcp', mcpRequest('tools/call', [
        'name' => 'list-stats-capabilities',
        'arguments' => [],
        '_meta' => $meta,
    ], 2), [
        ...mcpHeaders(null),
        'MCP-Protocol-Version' => '2026-07-28',
        'Mcp-Method' => 'tools/call',
        'Mcp-Name' => 'list-stats-capabilities',
    ]);

    $response->assertOk();
    expect($response->getContent())->toContain($this->project->name);
});
