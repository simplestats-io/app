<?php

use App\DTOs\StatsFilterDto;
use App\Enums\StatsRangeType;
use App\Models\Campaign;
use App\Models\Project;
use App\Models\StatsAggregation;
use App\Models\StatsTrackCampaign;
use App\Models\User;
use App\Services\CampaignService;
use App\Services\StatsAggregationsService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Database\Seeders\TestUserSeeder;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function (): void {
    $this->seed(TestUserSeeder::class);
    $this->user = User::where('email', config('app.test.user')['email'])->firstOrFail();
    $this->project = Project::first();
    $this->team = $this->project->team;
    $this->pid = $this->project->id;

    DB::table('stats_visitors')->delete();

    auth()->setUser($this->user);
    Context::add('team_id', $this->team->id);
    config(['app.stats_aggregation_enabled' => true]);
});

afterEach(function (): void {
    Carbon::setTestNow();
});

function makeFilterTrackCampaign(int $teamId, string $name, ?int $campaignId): int
{
    return DB::table('stats_track_campaigns')->insertGetId([
        'uuid' => (string) Str::uuid(),
        'team_id' => $teamId,
        'name' => $name,
        'campaign_id' => $campaignId,
        'created_at' => '2026-03-28 12:00:00',
        'updated_at' => '2026-03-28 12:00:00',
    ]);
}

it('narrows aggregated stats to every utm dimension the campaign owns', function (): void {
    $campaign = Campaign::create(['team_id' => $this->team->id, 'project_id' => $this->pid, 'name' => 'Summer Sale']);

    $linkedA = makeFilterTrackCampaign($this->team->id, 'summer_a', $campaign->id);
    $linkedB = makeFilterTrackCampaign($this->team->id, 'summer_b', $campaign->id);
    $unrelated = makeFilterTrackCampaign($this->team->id, 'unrelated', null);

    $insert = function (int $count, ?int $trackCampaignId): void {
        for ($i = 0; $i < $count; $i++) {
            DB::table('stats_visitors')->insert([
                'team_id' => $this->team->id,
                'project_id' => $this->pid,
                'visitor_hash' => Str::uuid()->toString(),
                'stats_track_campaign_id' => $trackCampaignId,
                'time' => '2026-03-30 12:00:00',
                'time_utc' => '2026-03-30 12:00:00',
            ]);
        }
    };

    $insert(4, $linkedA);
    $insert(3, $linkedB);
    $insert(5, $unrelated);
    $insert(2, null);

    $filters = new StatsFilterDto(
        projectId: $this->pid, rangeAmount: 1, rangeType: StatsRangeType::DAY,
        rangeStart: CarbonImmutable::parse('2026-03-30'), comparison: '0',
        campaign: $campaign->id,
    );

    $data = resolve(StatsAggregationsService::class)->getAggregatedData($filters);

    expect($data['2026-03-30']['pd_visitor'])->toBe(7);
});

it('returns zero stats for a campaign with no linked utm dimensions', function (): void {
    $campaign = Campaign::create(['team_id' => $this->team->id, 'project_id' => $this->pid, 'name' => 'Empty']);

    $unrelated = makeFilterTrackCampaign($this->team->id, 'unrelated', null);

    for ($i = 0; $i < 6; $i++) {
        DB::table('stats_visitors')->insert([
            'team_id' => $this->team->id,
            'project_id' => $this->pid,
            'visitor_hash' => Str::uuid()->toString(),
            'stats_track_campaign_id' => $unrelated,
            'time' => '2026-03-30 12:00:00',
            'time_utc' => '2026-03-30 12:00:00',
        ]);
    }

    $filters = new StatsFilterDto(
        projectId: $this->pid, rangeAmount: 1, rangeType: StatsRangeType::DAY,
        rangeStart: CarbonImmutable::parse('2026-03-30'), comparison: '0',
        campaign: $campaign->id,
    );

    $data = resolve(StatsAggregationsService::class)->getAggregatedData($filters);

    expect($data['2026-03-30']['pd_visitor'])->toBe(0);
});

it('drops cached campaign aggregations when a utm link is removed', function (): void {
    $campaign = Campaign::create(['team_id' => $this->team->id, 'project_id' => $this->pid, 'name' => 'Cached']);
    $linkedId = makeFilterTrackCampaign($this->team->id, 'cached_utm', $campaign->id);

    DB::table('stats_visitors')->insert([
        'team_id' => $this->team->id,
        'project_id' => $this->pid,
        'visitor_hash' => Str::uuid()->toString(),
        'stats_track_campaign_id' => $linkedId,
        'time' => '2026-03-30 12:00:00',
        'time_utc' => '2026-03-30 12:00:00',
    ]);

    $filters = new StatsFilterDto(
        projectId: $this->pid, rangeAmount: 1, rangeType: StatsRangeType::DAY,
        rangeStart: CarbonImmutable::parse('2026-03-30'), comparison: '0',
        campaign: $campaign->id,
    );

    resolve(StatsAggregationsService::class)->getAggregatedData($filters);

    expect(StatsAggregation::where('filter_hash', $filters->makeHash())->count())->toBeGreaterThan(0);

    resolve(CampaignService::class)->unlinkTrackCampaign($campaign, StatsTrackCampaign::find($linkedId));

    expect(StatsAggregation::where('filter_hash', $filters->makeHash())->count())->toBe(0);
});

it('accepts a campaign filter on the dashboard for the current team', function (): void {
    $campaign = Campaign::create(['team_id' => $this->team->id, 'project_id' => $this->pid, 'name' => 'Mine']);

    $this->actingAs($this->user)
        ->get(route('dashboard', ['campaign' => $campaign->id, 'project_id' => $this->pid]))
        ->assertOk();
});

it('exposes the campaign summary on the dashboard when filtered by a campaign', function (): void {
    $campaign = Campaign::create(['team_id' => $this->team->id, 'project_id' => $this->pid, 'name' => 'Summary Test']);

    $this->actingAs($this->user)
        ->get(route('dashboard', ['campaign' => $campaign->id, 'project_id' => $this->pid]))
        ->assertInertia(fn ($page) => $page
            ->where('campaign_summary.name', 'Summary Test')
            ->where('campaign_summary.spend', 0)
        );
});

it('suppresses the campaign summary when another dimension filter is active', function (): void {
    $campaign = Campaign::create(['team_id' => $this->team->id, 'project_id' => $this->pid, 'name' => 'Filtered']);
    $utmId = makeFilterTrackCampaign($this->team->id, 'filtered_utm', $campaign->id);

    $this->actingAs($this->user)
        ->get(route('dashboard', ['campaign' => $campaign->id, 'track_campaign' => $utmId, 'project_id' => $this->pid]))
        ->assertInertia(fn ($page) => $page->where('campaign_summary', null));
});

it('has no campaign summary on the unfiltered dashboard', function (): void {
    $this->actingAs($this->user)
        ->get(route('dashboard', ['project_id' => $this->pid]))
        ->assertInertia(fn ($page) => $page->where('campaign_summary', null));
});

it('rejects a campaign filter referencing another team', function (): void {
    $otherTeamId = User::factory()->withPersonalTeam()->create()->currentTeam->id;
    $foreignCampaignId = DB::table('campaigns')->insertGetId([
        'uuid' => (string) Str::uuid(),
        'team_id' => $otherTeamId,
        'project_id' => $this->pid,
        'name' => 'Foreign',
        'created_at' => '2026-03-28 12:00:00',
        'updated_at' => '2026-03-28 12:00:00',
    ]);

    $this->actingAs($this->user)
        ->get(route('dashboard', ['campaign' => $foreignCampaignId, 'project_id' => $this->pid]))
        ->assertSessionHasErrors('campaign');
});

it('only counts the campaign project when all projects are selected', function (): void {
    $otherProject = Project::factory()->create(['team_id' => $this->team->id]);
    $campaign = Campaign::create(['team_id' => $this->team->id, 'project_id' => $this->pid, 'name' => 'Black Week']);
    $linked = makeFilterTrackCampaign($this->team->id, 'black-week', $campaign->id);

    foreach ([$this->pid, $this->pid, $otherProject->id] as $projectId) {
        DB::table('stats_visitors')->insert([
            'team_id' => $this->team->id,
            'project_id' => $projectId,
            'visitor_hash' => Str::uuid()->toString(),
            'stats_track_campaign_id' => $linked,
            'time' => '2026-03-30 12:00:00',
            'time_utc' => '2026-03-30 12:00:00',
        ]);
    }

    $filters = new StatsFilterDto(
        projectId: StatsFilterDto::TRACK_ALL, rangeAmount: 1, rangeType: StatsRangeType::DAY,
        rangeStart: CarbonImmutable::parse('2026-03-30'), comparison: '0',
        campaign: $campaign->id,
    );

    expect(resolve(StatsAggregationsService::class)->getAggregatedData($filters)['2026-03-30']['pd_visitor'])->toBe(2);
});

it('counts every project for a campaign for all projects, narrowed by the project filter', function (): void {
    $otherProject = Project::factory()->create(['team_id' => $this->team->id]);
    $campaign = Campaign::create(['team_id' => $this->team->id, 'project_id' => null, 'name' => 'Black Friday']);
    $linked = makeFilterTrackCampaign($this->team->id, 'bf', $campaign->id);

    foreach ([$this->pid, $this->pid, $otherProject->id] as $projectId) {
        DB::table('stats_visitors')->insert([
            'team_id' => $this->team->id,
            'project_id' => $projectId,
            'visitor_hash' => Str::uuid()->toString(),
            'stats_track_campaign_id' => $linked,
            'time' => '2026-03-30 12:00:00',
            'time_utc' => '2026-03-30 12:00:00',
        ]);
    }

    $visitorsFor = fn (int $projectId): int => resolve(StatsAggregationsService::class)->getAggregatedData(new StatsFilterDto(
        projectId: $projectId, rangeAmount: 1, rangeType: StatsRangeType::DAY,
        rangeStart: CarbonImmutable::parse('2026-03-30'), comparison: '0',
        campaign: $campaign->id,
    ))['2026-03-30']['pd_visitor'];

    expect($visitorsFor(StatsFilterDto::TRACK_ALL))->toBe(3)
        ->and($visitorsFor($otherProject->id))->toBe(1);
});

it('shows the summary of a campaign for all projects only in the all projects view', function (): void {
    Project::factory()->create(['team_id' => $this->team->id]);
    $campaign = Campaign::create(['team_id' => $this->team->id, 'project_id' => null, 'name' => 'Black Friday']);

    $this->actingAs($this->user)
        ->get(route('dashboard', ['campaign' => $campaign->uuid, 'project_id' => 0]))
        ->assertInertia(fn ($page) => $page->where('campaign_summary.name', 'Black Friday')->where('campaign_summary.project_name', 'All projects'));

    $this->actingAs($this->user)
        ->get(route('dashboard', ['campaign' => $campaign->uuid, 'project_id' => $this->project->uuid]))
        ->assertInertia(fn ($page) => $page->where('campaign_summary', null));
});

it('rejects a campaign for all projects as filter for a member limited to selected projects', function (): void {
    $campaign = Campaign::create(['team_id' => $this->team->id, 'project_id' => null, 'name' => 'Black Friday']);
    $member = User::factory()->create();
    $this->team->users()->attach($member, ['role' => 'viewer', 'project_ids' => [$this->pid]]);
    $member->switchTeam($this->team);
    Context::forget('team_id');

    $this->actingAs($member->fresh())
        ->get(route('dashboard', ['campaign' => $campaign->id, 'project_id' => $this->pid]))
        ->assertSessionHasErrors('campaign');
});
