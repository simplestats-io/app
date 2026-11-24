<?php

use App\Models\Campaign;
use App\Models\Project;
use App\Models\StatsTrackCampaign;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

const USAGE_WITHOUT_PAYMENTS = Project::USAGE_VISITORS | Project::USAGE_USERS;

beforeEach(function (): void {
    $this->user = User::factory()->withPersonalTeam()->create();
    $this->team = $this->user->currentTeam;
    $this->project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->actingAs($this->user);
});

test('storing a campaign creates it and redirects to its detail page', function (): void {
    $response = $this->post(route('campaigns.store'), [
        'project_id' => $this->project->id,
        'name' => 'Summer Sale 2026',
    ]);

    $campaign = Campaign::where('name', 'Summer Sale 2026')->first();

    expect($campaign)->not->toBeNull()
        ->and($campaign->team_id)->toBe($this->team->id)
        ->and($campaign->project_id)->toBe($this->project->id);

    $response->assertRedirect(route('campaigns.show', $campaign));
});

test('storing an existing campaign name in the same project is idempotent', function (): void {
    $existing = Campaign::factory()->create([
        'team_id' => $this->team->id,
        'project_id' => $this->project->id,
        'name' => 'Black Friday',
    ]);

    $this->post(route('campaigns.store'), [
        'project_id' => $this->project->id,
        'name' => 'Black Friday',
    ])->assertRedirect(route('campaigns.show', $existing));

    expect(Campaign::where('name', 'Black Friday')->count())->toBe(1);
});

test('campaign name and project are required', function (): void {
    $this->post(route('campaigns.store'), [])
        ->assertSessionHasErrors(['project_id', 'name']);
});

test('campaign rejects a project from another team', function (): void {
    $otherUser = User::factory()->withPersonalTeam()->create();
    $this->actingAs($otherUser);
    $otherProject = Project::factory()->create(['team_id' => $otherUser->currentTeam->id]);
    $this->actingAs($this->user);

    $this->post(route('campaigns.store'), [
        'project_id' => $otherProject->id,
        'name' => 'Cross team',
    ])->assertSessionHasErrors('project_id');
});

test('updating a campaign renames it', function (): void {
    $campaign = Campaign::factory()->create([
        'team_id' => $this->team->id,
        'project_id' => $this->project->id,
        'name' => 'Old name',
    ]);

    $this->patch(route('campaigns.update', $campaign), ['name' => 'New name'])
        ->assertRedirect();

    expect($campaign->fresh()->name)->toBe('New name');
});

test('updating a campaign requires a name', function (): void {
    $campaign = Campaign::factory()->create(['team_id' => $this->team->id, 'project_id' => $this->project->id]);

    $this->patch(route('campaigns.update', $campaign), ['name' => ''])
        ->assertSessionHasErrors('name');
});

test('updating a campaign of another team is not possible', function (): void {
    $otherUser = User::factory()->withPersonalTeam()->create();
    $this->actingAs($otherUser);
    $otherProject = Project::factory()->create(['team_id' => $otherUser->currentTeam->id]);
    $campaign = Campaign::factory()->create([
        'team_id' => $otherUser->currentTeam->id,
        'project_id' => $otherProject->id,
        'name' => 'Orig',
    ]);
    $this->actingAs($this->user);

    $this->patch(route('campaigns.update', $campaign), ['name' => 'Hijack'])
        ->assertNotFound();

    expect($campaign->fresh()->name)->toBe('Orig');
});

test('linking a utm value creates and attaches the tracked dimension', function (): void {
    $campaign = Campaign::factory()->create([
        'team_id' => $this->team->id,
        'project_id' => $this->project->id,
    ]);

    $this->post(route('campaigns.track-campaigns.link', $campaign), ['name' => 'summer_sale_2026'])
        ->assertRedirect();

    $this->assertDatabaseHas('stats_track_campaigns', [
        'team_id' => $this->team->id,
        'name' => 'summer_sale_2026',
        'campaign_id' => $campaign->id,
    ]);
});

test('linking an already tracked utm value attaches the existing row', function (): void {
    $campaign = Campaign::factory()->create([
        'team_id' => $this->team->id,
        'project_id' => $this->project->id,
    ]);

    $utmId = DB::table('stats_track_campaigns')->insertGetId([
        'uuid' => (string) Str::uuid(),
        'team_id' => $this->team->id,
        'name' => 'existing_utm',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->post(route('campaigns.track-campaigns.link', $campaign), ['name' => 'existing_utm'])
        ->assertRedirect();

    expect(StatsTrackCampaign::where('name', 'existing_utm')->count())->toBe(1)
        ->and(StatsTrackCampaign::find($utmId)->campaign_id)->toBe($campaign->id);
});

test('linking a utm value already owned by another campaign is rejected', function (): void {
    $other = Campaign::factory()->create(['team_id' => $this->team->id, 'project_id' => $this->project->id]);
    $campaign = Campaign::factory()->create(['team_id' => $this->team->id, 'project_id' => $this->project->id]);

    DB::table('stats_track_campaigns')->insert([
        'uuid' => (string) Str::uuid(),
        'team_id' => $this->team->id,
        'name' => 'taken_utm',
        'campaign_id' => $other->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->post(route('campaigns.track-campaigns.link', $campaign), ['name' => 'taken_utm'])
        ->assertSessionHasErrors('name');

    expect(StatsTrackCampaign::where('name', 'taken_utm')->first()->campaign_id)->toBe($other->id);
});

test('campaigns index is forbidden when no project tracks payments', function (): void {
    $this->team->projects()->update(['usage' => USAGE_WITHOUT_PAYMENTS]);

    $this->get(route('campaigns'))->assertForbidden();
});

test('storing a campaign is forbidden when no project tracks payments', function (): void {
    $this->team->projects()->update(['usage' => USAGE_WITHOUT_PAYMENTS]);

    $this->post(route('campaigns.store'), [
        'project_id' => $this->project->id,
        'name' => 'No payments',
    ])->assertForbidden();
});

test('viewing a campaign is forbidden when its project tracks no payments', function (): void {
    $this->project->update(['usage' => USAGE_WITHOUT_PAYMENTS]);
    $campaign = Campaign::factory()->create(['team_id' => $this->team->id, 'project_id' => $this->project->id]);

    $this->get(route('campaigns.show', $campaign))->assertForbidden();
});

test('unlinking detaches the tracked dimension', function (): void {
    $campaign = Campaign::factory()->create(['team_id' => $this->team->id, 'project_id' => $this->project->id]);

    $utmId = DB::table('stats_track_campaigns')->insertGetId([
        'uuid' => (string) Str::uuid(),
        'team_id' => $this->team->id,
        'name' => 'linked_utm',
        'campaign_id' => $campaign->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->delete(route('campaigns.track-campaigns.unlink', [$campaign, StatsTrackCampaign::find($utmId)]))
        ->assertRedirect();

    expect(StatsTrackCampaign::find($utmId)->campaign_id)->toBeNull();
});

function limitedCampaignMember(Project $project, string $role = 'editor'): User
{
    $member = User::factory()->create();
    $project->team->users()->attach($member, ['role' => $role, 'project_ids' => [$project->id]]);
    $member->switchTeam($project->team);

    return $member->fresh();
}

test('an unrestricted member creates a campaign for all projects', function (): void {
    $this->post(route('campaigns.store'), ['project_id' => 'all', 'name' => 'Black Friday'])
        ->assertSessionHasNoErrors();

    expect(Campaign::where('name', 'Black Friday')->sole())
        ->project_id->toBeNull()
        ->team_id->toBe($this->team->id);

    $this->post(route('campaigns.store'), ['project_id' => 'all', 'name' => 'Black Friday']);
    $this->post(route('campaigns.store'), ['project_id' => $this->project->uuid, 'name' => 'Black Friday']);

    expect(Campaign::where('name', 'Black Friday')->count())->toBe(2);
});

test('an unknown project uuid never becomes a campaign for all projects', function (): void {
    $this->post(route('campaigns.store'), ['project_id' => (string) Str::uuid(), 'name' => 'Black Friday'])
        ->assertSessionHasErrors('project_id');

    expect(Campaign::count())->toBe(0);
});

test('a member limited to selected projects cannot create a campaign for all projects', function (): void {
    $this->actingAs(limitedCampaignMember($this->project));

    $this->post(route('campaigns.store'), ['project_id' => 'all', 'name' => 'Black Friday'])
        ->assertSessionHasErrors(['project_id' => 'Select one of your projects. Campaigns for all projects need access to every project.']);

    expect(Campaign::withoutGlobalScopes()->count())->toBe(0);
});

test('a member limited to selected projects cannot open, rename or fund a campaign for all projects', function (): void {
    $campaign = Campaign::create(['team_id' => $this->team->id, 'project_id' => null, 'name' => 'Black Friday']);
    $this->actingAs(limitedCampaignMember($this->project));

    $this->get(route('campaigns.show', $campaign))->assertNotFound();
    $this->patch(route('campaigns.update', $campaign), ['name' => 'Hijacked'])->assertNotFound();
    $this->post(route('ad-spends.store'), [
        'campaign_id' => $campaign->uuid, 'amount' => 10, 'currency' => 'EUR', 'starts_on' => '2026-06-01', 'ends_on' => '2026-06-10',
    ])->assertSessionHasErrors('campaign_id');

    expect($campaign->fresh()->name)->toBe('Black Friday')
        ->and($campaign->adSpends()->withoutGlobalScopes()->count())->toBe(0);
});

test('renaming a campaign for all projects to a name already used in that scope fails', function (): void {
    Campaign::create(['team_id' => $this->team->id, 'project_id' => null, 'name' => 'Black Friday']);
    Campaign::factory()->create(['project_id' => $this->project->id, 'name' => 'Cyber Monday']);
    $campaign = Campaign::create(['team_id' => $this->team->id, 'project_id' => null, 'name' => 'Spring']);

    $this->patch(route('campaigns.update', $campaign), ['name' => 'Black Friday'])->assertSessionHasErrors('name');
    $this->patch(route('campaigns.update', $campaign), ['name' => 'Cyber Monday'])->assertSessionHasNoErrors();

    expect($campaign->fresh()->name)->toBe('Cyber Monday');
});
