<?php

use App\Models\AdSpend;
use App\Models\Campaign;
use App\Models\Project;
use App\Models\User;
use Carbon\CarbonImmutable;

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-06-21 12:00:00');

    $this->user = User::factory()->withPersonalTeam()->create();
    $this->user->currentTeam->update(['currency' => 'EUR']);
    $this->team = $this->user->currentTeam;
    $this->project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->actingAs($this->user);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

test('campaigns route returns 200', function (): void {
    $this->get(route('campaigns'))->assertStatus(200);
});

test('campaigns renders Campaigns/Index with the shared filter and profitability props', function (): void {
    $this->get(route('campaigns'))
        ->assertInertia(fn ($page) => $page
            ->component('Campaigns/Index')
            ->has('teamStats')
            ->has('presets')
            ->has('filters')
            ->has('rangeTypes')
            ->has('usageBits')
            ->has('campaigns')
            ->has('totals', fn ($totals) => $totals->has('spend')->has('net')->has('shared_excluded'))
            ->has('projects')
            ->missing('adSpends')
            ->missing('availableCampaigns')
        );
});

test('campaigns lists a campaign with its spend as a profitability row', function (): void {
    $campaign = Campaign::factory()->create([
        'team_id' => $this->team->id,
        'project_id' => $this->project->id,
        'name' => 'Google Ads',
    ]);

    AdSpend::factory()->create([
        'team_id' => $this->team->id,
        'campaign_id' => $campaign->id,
        'amount' => 5000,
        'starts_on' => '2026-06-17',
        'ends_on' => '2026-06-21',
    ]);

    $this->get(route('campaigns', ['range_type' => 'days', 'range_amount' => 7, 'range_start' => '2026-06-21']))
        ->assertInertia(fn ($page) => $page
            ->component('Campaigns/Index')
            ->has('campaigns.data', 1, fn ($row) => $row
                ->where('campaign_uuid', $campaign->uuid)
                ->where('name', 'Google Ads')
                ->where('spend', 5000)
                ->where('net', 0)
                ->where('net_profit', -5000)
                ->etc()
            )
            ->has('campaigns.links')
        );
});

test('campaigns exposes the run dates across all spend entries and flags activity in the range', function (): void {
    $campaign = Campaign::factory()->create([
        'team_id' => $this->team->id,
        'project_id' => $this->project->id,
    ]);

    AdSpend::factory()->create([
        'team_id' => $this->team->id,
        'campaign_id' => $campaign->id,
        'amount' => 5000,
        'starts_on' => '2026-03-02',
        'ends_on' => '2026-03-08',
    ]);

    AdSpend::factory()->create([
        'team_id' => $this->team->id,
        'campaign_id' => $campaign->id,
        'amount' => 3000,
        'starts_on' => '2026-01-10',
        'ends_on' => '2026-01-12',
    ]);

    $this->get(route('campaigns', ['range_type' => 'days', 'range_amount' => 7, 'range_start' => '2026-06-21']))
        ->assertInertia(fn ($page) => $page
            ->has('campaigns.data', 1, fn ($row) => $row
                ->where('runs_from', '2026-01-10')
                ->where('runs_until', '2026-03-08')
                ->where('spend', 0)
                ->where('has_activity', false)
                ->etc()
            )
        );

    $this->get(route('campaigns', ['range_type' => 'months', 'range_amount' => 6, 'range_start' => '2026-06-21']))
        ->assertInertia(fn ($page) => $page
            ->has('campaigns.data', 1, fn ($row) => $row
                ->where('spend', 8000)
                ->where('has_activity', true)
                ->etc()
            )
        );
});

test('campaigns lists a freshly created campaign even without spend', function (): void {
    Campaign::factory()->create([
        'team_id' => $this->team->id,
        'project_id' => $this->project->id,
        'name' => 'Pre-launch',
    ]);

    $this->get(route('campaigns'))
        ->assertInertia(fn ($page) => $page
            ->has('campaigns.data', 1, fn ($row) => $row
                ->where('name', 'Pre-launch')
                ->where('spend', 0)
                ->etc()
            )
        );
});

test('campaigns paginates the profitability list at 20 per page', function (): void {
    Campaign::factory()->count(25)->create([
        'team_id' => $this->team->id,
        'project_id' => $this->project->id,
    ]);

    $this->get(route('campaigns'))
        ->assertInertia(fn ($page) => $page
            ->has('campaigns.data', 20)
            ->where('campaigns.total', 25)
            ->where('campaigns.per_page', 20)
        );

    $this->get(route('campaigns', ['page' => 2]))
        ->assertInertia(fn ($page) => $page
            ->has('campaigns.data', 5)
            ->where('campaigns.current_page', 2)
        );
});

test('campaigns exposes ltv:cac and cac payback keys on each row', function (): void {
    $this->project->update(['usage' => Project::USAGE_VISITORS | Project::USAGE_USERS | Project::USAGE_PAYMENTS | Project::USAGE_SUBSCRIPTION]);

    Campaign::factory()->create([
        'team_id' => $this->team->id,
        'project_id' => $this->project->id,
        'name' => 'Sub Ads',
    ]);

    $this->get(route('campaigns'))
        ->assertInertia(fn ($page) => $page
            ->has('campaigns.data', 1, fn ($row) => $row
                ->where('ltv_cac', null)
                ->where('cac_payback', null)
                ->etc()
            )
        );
});

test('campaigns for all projects are listed in the all projects view and offered in the modal', function (): void {
    Project::factory()->create(['team_id' => $this->team->id]);
    Campaign::create(['team_id' => $this->team->id, 'project_id' => null, 'name' => 'Black Friday']);
    Campaign::factory()->create(['project_id' => $this->project->id, 'name' => 'Spring Sale']);

    $this->get(route('campaigns', ['project_id' => 0]))
        ->assertInertia(fn ($page) => $page
            ->where('canCreateForAllProjects', true)
            ->where('campaigns.data', fn ($rows): bool => collect($rows)->firstWhere('name', 'Black Friday')['project_name'] === 'All projects'));

    $this->get(route('campaigns', ['project_id' => $this->project->uuid]))
        ->assertInertia(fn ($page) => $page->where('campaigns.data', fn ($rows): bool => collect($rows)->pluck('name')->all() === ['Spring Sale']));
});

test('a member limited to selected projects sees neither campaigns for all projects nor the option', function (): void {
    $otherProject = Project::factory()->create(['team_id' => $this->team->id]);
    Campaign::create(['team_id' => $this->team->id, 'project_id' => null, 'name' => 'Black Friday']);
    Campaign::factory()->create(['project_id' => $otherProject->id, 'name' => 'Blog Sale']);
    Campaign::factory()->create(['project_id' => $this->project->id, 'name' => 'Spring Sale']);

    $member = User::factory()->create();
    $this->team->users()->attach($member, ['role' => 'editor', 'project_ids' => [$this->project->id]]);
    $member->switchTeam($this->team);

    $this->actingAs($member->fresh())
        ->get(route('campaigns'))
        ->assertInertia(fn ($page) => $page
            ->where('canCreateForAllProjects', false)
            ->where('campaigns.data', fn ($rows): bool => collect($rows)->pluck('name')->all() === ['Spring Sale']));
});
