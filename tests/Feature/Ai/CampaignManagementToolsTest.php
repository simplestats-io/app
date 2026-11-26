<?php

use App\Enums\McpScope;
use App\Mcp\Servers\SimpleStatsServer;
use App\Mcp\Tools\CreateCampaign;
use App\Mcp\Tools\ListCampaigns;
use App\Mcp\Tools\RecordAdSpend;
use App\Mcp\Tools\UpdateAdSpend;
use App\Mcp\Tools\UpdateCampaign;
use App\Models\AdSpend;
use App\Models\Campaign;
use App\Models\Project;
use App\Models\StatsTrackCampaign;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Passport\Passport;

beforeEach(function (): void {
    $this->owner = User::factory()->withPersonalTeam()->create();
    $this->team = $this->owner->currentTeam;
    $this->team->update(['currency' => 'EUR']);

    $this->project = Project::factory()->create(['team_id' => $this->team->id, 'name' => 'Shop']);
    $this->otherProject = Project::factory()->create(['team_id' => $this->team->id, 'name' => 'Blog']);
});

function actAsCampaignOwner(): void
{
    Passport::actingAs(test()->owner, [McpScope::READ->value, McpScope::WRITE->value]);
}

function campaignTool(string $tool, array $arguments = []): TestResponse
{
    actAsCampaignOwner();

    return test()->postJson('/mcp', [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'tools/call',
        'params' => ['name' => $tool, 'arguments' => $arguments],
    ], [
        'Accept' => 'application/json, text/event-stream',
    ])->assertOk();
}

/**
 * @return array<int, array<string, mixed>>
 */
function campaignToolList(): array
{
    actAsCampaignOwner();

    return test()->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list', 'params' => []], [
        'Accept' => 'application/json, text/event-stream',
    ])->assertOk()->json('result.tools');
}

function campaignWithSpend(Project $project, string $name = 'Spring Sale'): Campaign
{
    $campaign = Campaign::factory()->create(['project_id' => $project->id, 'name' => $name]);

    AdSpend::factory()->create([
        'team_id' => $project->team_id,
        'campaign_id' => $campaign->id,
        'amount' => 5000,
        'currency' => 'EUR',
        'starts_on' => '2026-03-01',
        'ends_on' => '2026-03-10',
    ]);

    return $campaign;
}

function actAsTeamMember(Team $team, string $role, ?array $projectIds = null): User
{
    $member = User::factory()->create();
    $team->users()->attach($member, ['role' => $role, 'project_ids' => $projectIds]);
    $member->switchTeam($team);

    Context::add('team_id', $team->id);

    return $member->fresh();
}

it('creates a campaign in a project and links its utm values', function (): void {
    $response = campaignTool('create-campaign', [
        'name' => 'Black Week 2026',
        'project_id' => $this->project->id,
        'utm_campaigns' => ['black-week-2026'],
    ]);

    $response->assertJsonPath('result.structuredContent.created', true)
        ->assertJsonPath('result.structuredContent.campaign.project', 'Shop')
        ->assertJsonPath('result.structuredContent.campaign.all_projects', false)
        ->assertJsonPath('result.structuredContent.campaign.utm_campaigns', ['black-week-2026']);

    $campaign = Campaign::withoutGlobalScopes()->where('name', 'Black Week 2026')->sole();

    expect($campaign->project_id)->toBe($this->project->id)
        ->and($campaign->team_id)->toBe($this->team->id)
        ->and(StatsTrackCampaign::withoutGlobalScopes()->where('name', 'black-week-2026')->sole())
        ->team_id->toBe($this->team->id)
        ->campaign_id->toBe($campaign->id);
});

it('creates a campaign for all projects without a project id and lists it', function (mixed $projectId): void {
    $arguments = ['name' => 'Black Friday', 'utm_campaigns' => ['bf']];

    campaignTool('create-campaign', $projectId === 'omitted' ? $arguments : [...$arguments, 'project_id' => $projectId])
        ->assertJsonPath('result.structuredContent.campaign.project', 'All projects')
        ->assertJsonPath('result.structuredContent.campaign.project_id', 0)
        ->assertJsonPath('result.structuredContent.campaign.all_projects', true);

    expect(Campaign::withoutGlobalScopes()->where('name', 'Black Friday')->sole())
        ->project_id->toBeNull()
        ->team_id->toBe($this->team->id);

    campaignTool('list-campaigns')
        ->assertJsonPath('result.structuredContent.campaigns.0.name', 'Black Friday')
        ->assertJsonPath('result.structuredContent.campaigns.0.all_projects', true)
        ->assertJsonPath('result.structuredContent.campaigns.0.utm_campaigns', ['bf']);

    campaignTool('list-campaigns', ['project_id' => $this->project->id])
        ->assertJsonPath('result.structuredContent.campaigns', []);
})->with(['omitted' => 'omitted', 'zero' => 0]);

it('keeps campaign names unique per project and among the campaigns for all projects', function (): void {
    Campaign::factory()->create(['project_id' => $this->project->id, 'name' => 'Black Friday']);
    $teamWide = Campaign::create(['team_id' => $this->team->id, 'project_id' => null, 'name' => 'Black Friday']);
    $other = Campaign::create(['team_id' => $this->team->id, 'project_id' => null, 'name' => 'Cyber Monday']);

    campaignTool('update-campaign', ['campaign_id' => $other->uuid, 'name' => 'Black Friday'])
        ->assertJsonPath('result.isError', true)
        ->assertSee('The team already has a campaign for all projects with this name.');

    campaignTool('create-campaign', ['name' => 'Black Friday'])
        ->assertJsonPath('result.structuredContent.created', false)
        ->assertJsonPath('result.structuredContent.campaign.campaign_id', $teamWide->uuid);

    $foreignTeam = User::factory()->withPersonalTeam()->create()->currentTeam;
    DB::table('campaigns')->insert(['uuid' => (string) Str::uuid(), 'team_id' => $foreignTeam->id, 'project_id' => null, 'name' => 'Cyber Week', 'created_at' => now(), 'updated_at' => now()]);

    campaignTool('update-campaign', ['campaign_id' => $other->uuid, 'name' => 'Cyber Week'])
        ->assertJsonPath('result.isError', false);

    expect($other->fresh()->name)->toBe('Cyber Week')
        ->and(fn () => Campaign::create(['team_id' => $this->team->id, 'project_id' => null, 'name' => 'Black Friday']))
        ->toThrow(UniqueConstraintViolationException::class);
});

it('reuses an existing campaign of the same name instead of duplicating it', function (): void {
    campaignTool('create-campaign', ['name' => 'Black Week 2026']);

    campaignTool('create-campaign', ['name' => 'Black Week 2026', 'utm_campaigns' => ['black-week-2026']])
        ->assertJsonPath('result.structuredContent.created', false)
        ->assertJsonPath('result.structuredContent.campaign.utm_campaigns', ['black-week-2026']);

    expect(Campaign::withoutGlobalScopes()->where('name', 'Black Week 2026')->count())->toBe(1);
});

it('records ad spend per platform in cents and lists it in major units', function (): void {
    $campaign = Campaign::factory()->create(['project_id' => $this->project->id, 'name' => 'Black Week 2026']);

    campaignTool('record-ad-spend', [
        'campaign_id' => $campaign->uuid,
        'name' => ' X  Ads ',
        'utm_source' => 'x',
        'amount' => 115,
        'starts_on' => '2026-11-23',
        'ends_on' => '2026-11-30',
    ]);
    campaignTool('record-ad-spend', [
        'campaign_id' => $campaign->uuid,
        'amount' => 49.9,
        'currency' => 'eur',
        'starts_on' => '2026-11-23',
        'ends_on' => '2026-11-30',
    ]);

    $spends = AdSpend::withoutGlobalScopes()->where('campaign_id', $campaign->id)->orderBy('amount')->get();

    expect($spends->pluck('amount')->all())->toBe([4990, 11500])
        ->and($spends->pluck('currency')->unique()->all())->toBe(['EUR'])
        ->and($spends->pluck('team_id')->unique()->all())->toBe([$this->team->id]);

    campaignTool('list-campaigns')
        ->assertJsonPath('result.structuredContent.campaigns.0.name', 'Black Week 2026')
        ->assertJsonPath('result.structuredContent.campaigns.0.ad_spends.0.amount', 115)
        ->assertJsonPath('result.structuredContent.campaigns.0.ad_spends.0.starts_on', '2026-11-23')
        ->assertJsonPath('result.structuredContent.campaigns.0.ad_spends.0.name', 'X Ads')
        ->assertJsonPath('result.structuredContent.campaigns.0.ad_spends.0.utm_source', 'x')
        ->assertJsonPath('result.structuredContent.campaigns.0.ad_spends.1.utm_source', null);
});

it('updates only the given ad spend fields', function (): void {
    $campaign = campaignWithSpend($this->project);
    $adSpend = $campaign->adSpends()->withoutGlobalScopes()->sole();
    $adSpend->update(['name' => 'Reddit', 'utm_source' => 'reddit']);

    campaignTool('update-ad-spend', ['ad_spend_id' => $adSpend->uuid, 'amount' => 62.5, 'utm_source' => '']);

    expect($adSpend->fresh())
        ->name->toBe('Reddit')
        ->utm_source->toBeNull()
        ->amount->toBe(6250)
        ->currency->toBe('EUR')
        ->starts_on->toDateString()->toBe('2026-03-01')
        ->ends_on->toDateString()->toBe('2026-03-10');
});

it('renames a campaign and links additional utm values', function (): void {
    $campaign = Campaign::factory()->create(['project_id' => $this->project->id, 'name' => 'Black Week']);

    campaignTool('update-campaign', [
        'campaign_id' => $campaign->uuid,
        'name' => 'Black Week 2026',
        'link_utm_campaigns' => ['black-week-2026', 'bw26'],
    ])->assertJsonPath('result.structuredContent.campaign.utm_campaigns', ['black-week-2026', 'bw26']);

    expect($campaign->fresh()->name)->toBe('Black Week 2026');
});

it('rejects invalid arguments', function (string $tool, array $arguments, string $message): void {
    $campaign = Campaign::factory()->create(['project_id' => $this->project->id]);
    $arguments = array_map(fn (mixed $value): mixed => $value === 'CAMPAIGN' ? $campaign->uuid : $value, $arguments);

    campaignTool($tool, $arguments)
        ->assertJsonPath('result.isError', true)
        ->assertSee($message);

    expect(AdSpend::withoutGlobalScopes()->count())->toBe(0)
        ->and(Campaign::withoutGlobalScopes()->count())->toBe(1);
})->with([
    'campaign without name' => ['create-campaign', [], 'The name field is required.'],
    'utm value too long' => ['create-campaign', ['name' => 'X', 'utm_campaigns' => [str_repeat('a', 253)]], 'must not be greater than 252 characters'],
    'spend ending before it starts' => ['record-ad-spend', ['campaign_id' => 'CAMPAIGN', 'amount' => 10, 'starts_on' => '2026-11-30', 'ends_on' => '2026-11-23'], 'The ends on field must be a date after or equal to starts on.'],
    'negative spend' => ['record-ad-spend', ['campaign_id' => 'CAMPAIGN', 'amount' => -5, 'starts_on' => '2026-11-23', 'ends_on' => '2026-11-30'], 'The amount field must be at least 0.'],
    'spend in another currency' => ['record-ad-spend', ['campaign_id' => 'CAMPAIGN', 'amount' => 10, 'currency' => 'USD', 'starts_on' => '2026-11-23', 'ends_on' => '2026-11-30'], 'Ad spend must be entered in the team currency (EUR).'],
    'malformed campaign id' => ['record-ad-spend', ['campaign_id' => 'not-a-uuid', 'amount' => 10, 'starts_on' => '2026-11-23', 'ends_on' => '2026-11-30'], 'The campaign id field must be a valid UUID.'],
]);

it('refuses renaming a campaign to a name the project already uses', function (): void {
    Campaign::factory()->create(['project_id' => $this->project->id, 'name' => 'Black Week 2026']);
    $campaign = Campaign::factory()->create(['project_id' => $this->project->id, 'name' => 'Black Week']);

    campaignTool('update-campaign', ['campaign_id' => $campaign->uuid, 'name' => 'Black Week 2026'])
        ->assertJsonPath('result.isError', true)
        ->assertSee('The project already has a campaign with this name.');

    expect($campaign->fresh()->name)->toBe('Black Week');
});

it('refuses a utm value that belongs to another campaign and creates nothing', function (): void {
    $taken = Campaign::factory()->create(['project_id' => $this->project->id, 'name' => 'Existing']);
    StatsTrackCampaign::withoutGlobalScopes()->create(['team_id' => $this->team->id, 'name' => 'black-week-2026', 'campaign_id' => $taken->id]);

    campaignTool('create-campaign', ['name' => 'Black Week 2026', 'utm_campaigns' => ['bw26', 'black-week-2026']])
        ->assertJsonPath('result.isError', true)
        ->assertSee('already linked to another campaign');

    expect(Campaign::withoutGlobalScopes()->where('name', 'Black Week 2026')->exists())->toBeFalse()
        ->and(StatsTrackCampaign::withoutGlobalScopes()->where('name', 'bw26')->exists())->toBeFalse();
});

it('refuses campaigns for a project that does not track payments', function (): void {
    $this->project->update(['usage' => Project::USAGE_VISITORS]);

    $campaign = Campaign::factory()->create(['project_id' => $this->project->id]);

    campaignTool('create-campaign', ['name' => 'Black Week 2026', 'project_id' => $this->project->id])
        ->assertJsonPath('result.isError', true)
        ->assertSee('does not track payments');

    campaignTool('record-ad-spend', ['campaign_id' => $campaign->uuid, 'amount' => 10, 'starts_on' => '2026-11-23', 'ends_on' => '2026-11-30'])
        ->assertJsonPath('result.isError', true)
        ->assertSee('does not track payments');

    expect(Campaign::withoutGlobalScopes()->count())->toBe(1)
        ->and(AdSpend::withoutGlobalScopes()->count())->toBe(0);
});

it('keeps oauth users away from the campaigns of other teams', function (): void {
    $foreignTeam = User::factory()->withPersonalTeam()->create()->currentTeam;
    $foreignCampaign = campaignWithSpend(Project::factory()->create(['team_id' => $foreignTeam->id]), 'Foreign Campaign');

    campaignTool('update-campaign', ['campaign_id' => $foreignCampaign->uuid, 'name' => 'Hijacked'])
        ->assertJsonPath('result.isError', true)
        ->assertSee('No campaign with this campaign_id exists');

    campaignTool('update-ad-spend', ['ad_spend_id' => $foreignCampaign->adSpends()->withoutGlobalScopes()->sole()->uuid, 'amount' => 1])
        ->assertJsonPath('result.isError', true);

    campaignTool('record-ad-spend', ['campaign_id' => $foreignCampaign->uuid, 'amount' => 10, 'starts_on' => '2026-11-23', 'ends_on' => '2026-11-30'])
        ->assertJsonPath('result.isError', true);

    campaignTool('list-campaigns')->assertDontSee('Foreign Campaign');

    expect($foreignCampaign->fresh()->name)->toBe('Foreign Campaign')
        ->and(AdSpend::withoutGlobalScopes()->where('campaign_id', $foreignCampaign->id)->count())->toBe(1);
});

it('lets a team editor create campaigns for any team project', function (): void {
    $editor = actAsTeamMember($this->team, 'editor');

    SimpleStatsServer::actingAs($editor)
        ->tool(CreateCampaign::class, ['name' => 'Black Week 2026', 'project_id' => $this->otherProject->id])
        ->assertOk()
        ->assertSee('Black Week 2026');

    expect(Campaign::withoutGlobalScopes()->where('name', 'Black Week 2026')->sole()->project_id)->toBe($this->otherProject->id);
});

it('denies writes to a viewer', function (): void {
    $campaign = Campaign::factory()->create(['project_id' => $this->project->id, 'name' => 'Black Week']);
    $viewer = actAsTeamMember($this->team, 'viewer');

    SimpleStatsServer::actingAs($viewer)
        ->tool(CreateCampaign::class, ['name' => 'Black Week 2026', 'project_id' => $this->project->id])
        ->assertHasErrors(['does not allow creating campaigns']);

    SimpleStatsServer::actingAs($viewer)
        ->tool(RecordAdSpend::class, ['campaign_id' => $campaign->uuid, 'amount' => 10, 'starts_on' => '2026-11-23', 'ends_on' => '2026-11-30'])
        ->assertHasErrors(['does not allow changing this campaign']);

    expect(Campaign::withoutGlobalScopes()->count())->toBe(1)
        ->and(AdSpend::withoutGlobalScopes()->count())->toBe(0);
});

it('limits a member with project access to their selected projects', function (): void {
    $ownCampaign = Campaign::factory()->create(['project_id' => $this->project->id, 'name' => 'Shop Campaign']);
    $foreignCampaign = Campaign::factory()->create(['project_id' => $this->otherProject->id, 'name' => 'Blog Campaign']);
    $editor = actAsTeamMember($this->team, 'editor', [$this->project->id]);

    SimpleStatsServer::actingAs($editor)
        ->tool(CreateCampaign::class, ['name' => 'Sneaky', 'project_id' => $this->otherProject->id])
        ->assertHasErrors(['The selected project id is invalid.']);

    SimpleStatsServer::actingAs($editor)
        ->tool(UpdateCampaign::class, ['campaign_id' => $foreignCampaign->uuid, 'name' => 'Hijacked'])
        ->assertHasErrors(['No campaign with this campaign_id exists']);

    SimpleStatsServer::actingAs($editor)
        ->tool(UpdateCampaign::class, ['campaign_id' => $ownCampaign->uuid, 'name' => 'Shop Campaign 2026'])
        ->assertOk();

    expect($foreignCampaign->fresh()->name)->toBe('Blog Campaign')
        ->and($ownCampaign->fresh()->name)->toBe('Shop Campaign 2026')
        ->and(Campaign::withoutGlobalScopes()->where('name', 'Sneaky')->exists())->toBeFalse();
});

it('keeps campaigns for all projects away from a member with project access', function (): void {
    $teamWide = Campaign::create(['team_id' => $this->team->id, 'project_id' => null, 'name' => 'Black Friday']);
    AdSpend::factory()->create(['team_id' => $this->team->id, 'campaign_id' => $teamWide->id, 'amount' => 5000, 'currency' => 'EUR', 'starts_on' => '2026-03-01', 'ends_on' => '2026-03-10']);
    $editor = actAsTeamMember($this->team, 'editor', [$this->project->id]);

    SimpleStatsServer::actingAs($editor)
        ->tool(CreateCampaign::class, ['name' => 'Cyber Monday'])
        ->assertHasErrors(['Campaigns for all projects need access to every project.']);

    SimpleStatsServer::actingAs($editor)
        ->tool(ListCampaigns::class)
        ->assertOk()
        ->assertDontSee('Black Friday');

    SimpleStatsServer::actingAs($editor)
        ->tool(UpdateCampaign::class, ['campaign_id' => $teamWide->uuid, 'name' => 'Hijacked'])
        ->assertHasErrors(['No campaign with this campaign_id exists']);

    SimpleStatsServer::actingAs($editor)
        ->tool(UpdateAdSpend::class, ['ad_spend_id' => $teamWide->adSpends()->withoutGlobalScopes()->sole()->uuid, 'amount' => 1])
        ->assertHasErrors(['No ad spend entry with this ad_spend_id exists']);

    expect(Campaign::withoutGlobalScopes()->where('name', 'Cyber Monday')->exists())->toBeFalse()
        ->and($teamWide->fresh()->name)->toBe('Black Friday');
});

it('refuses writes without an authenticated caller', function (): void {
    SimpleStatsServer::tool(CreateCampaign::class, ['name' => 'Black Week 2026', 'project_id' => $this->project->id])
        ->assertHasErrors(['requires a signed-in user']);

    SimpleStatsServer::tool(ListCampaigns::class)
        ->assertHasErrors(['requires a signed-in user']);

    expect(Campaign::withoutGlobalScopes()->count())->toBe(0);
});

it('marks only the managing tools as writing', function (): void {
    $tools = collect(campaignToolList())->mapWithKeys(fn (array $tool): array => [$tool['name'] => $tool['annotations']['readOnlyHint'] ?? null]);

    expect($tools->filter(fn (?bool $readOnly): bool => $readOnly === false)->keys()->sort()->values()->all())
        ->toBe(['create-campaign', 'create-project', 'invite-team-member', 'record-ad-spend', 'update-ad-spend', 'update-campaign', 'update-project', 'update-team-settings'])
        ->and($tools->reject(fn (?bool $readOnly): bool => $readOnly === false)->every(fn (?bool $readOnly): bool => $readOnly === true))->toBeTrue();
});
