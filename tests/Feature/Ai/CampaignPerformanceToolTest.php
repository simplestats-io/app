<?php

use App\Mcp\Servers\SimpleStatsServer;
use App\Mcp\Tools\GetCampaignPerformance;
use App\Models\Project;
use App\Models\User;
use Database\Seeders\TestUserSeeder;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function (): void {
    $this->seed(TestUserSeeder::class);
    $this->user = User::where('email', config('app.test.user')['email'])->firstOrFail();
    $this->project = Project::first();
    $this->team = $this->project->team;

    DB::table('stats_payments')->delete();
    DB::table('stats_users')->delete();
    DB::table('ad_spends')->delete();
    DB::table('stats_track_campaigns')->delete();
    DB::table('campaigns')->delete();

    auth()->setUser($this->user);
    Context::add('team_id', $this->team->id);
});

it('returns campaign profitability with money converted from cents', function (): void {
    $campaignId = DB::table('campaigns')->insertGetId([
        'uuid' => (string) Str::uuid(),
        'team_id' => $this->team->id,
        'project_id' => $this->project->id,
        'name' => 'Summer Sale',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $trackCampaignId = DB::table('stats_track_campaigns')->insertGetId([
        'uuid' => (string) Str::uuid(),
        'team_id' => $this->team->id,
        'name' => 'summer_sale',
        'campaign_id' => $campaignId,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $time = now()->subDay()->format('Y-m-d').' 12:00:00';
    $userId = (string) Str::uuid();

    DB::table('stats_users')->insert([
        'id' => $userId,
        'team_id' => $this->team->id,
        'project_id' => $this->project->id,
        'stats_track_campaign_id' => $trackCampaignId,
        'time' => $time,
        'time_utc' => $time,
    ]);

    // 10000 cents = 100.00 in team currency.
    DB::table('stats_payments')->insert([
        'id' => (string) Str::uuid(),
        'stats_user_id' => $userId,
        'team_id' => $this->team->id,
        'project_id' => $this->project->id,
        'gross' => 10000,
        'net' => 10000,
        'currency' => 'EUR',
        'counter' => 1,
        'time' => $time,
        'time_utc' => $time,
    ]);

    SimpleStatsServer::actingAs($this->user)
        ->tool(GetCampaignPerformance::class, ['preset' => 'last_7_days', 'project_id' => $this->project->id])
        ->assertOk()
        ->assertSee('Summer Sale')
        ->assertStructuredContent(function ($json): void {
            $json->where('campaigns.0.name', 'Summer Sale')
                ->where('campaigns.0.net', fn ($net): bool => (float) $net === 100.0)
                ->where('campaigns.0.reg', fn ($reg): bool => (int) $reg === 1)
                ->has('legend.roas')
                ->etc();
        });
});

it('notes when no campaigns exist', function (): void {
    SimpleStatsServer::actingAs($this->user)
        ->tool(GetCampaignPerformance::class, ['preset' => 'last_7_days'])
        ->assertOk()
        ->assertSee('No campaigns exist yet');
});

it('notes when the project does not track payments', function (): void {
    $this->project->update(['usage' => Project::USAGE_VISITORS]);

    SimpleStatsServer::actingAs($this->user)
        ->tool(GetCampaignPerformance::class, ['preset' => 'last_7_days', 'project_id' => $this->project->id])
        ->assertOk()
        ->assertSee('does not track payments');
});

it('only counts the traffic of the campaign project for a utm value shared across projects', function (): void {
    $otherProject = Project::factory()->create(['team_id' => $this->team->id]);

    $campaignId = DB::table('campaigns')->insertGetId([
        'uuid' => (string) Str::uuid(),
        'team_id' => $this->team->id,
        'project_id' => $this->project->id,
        'name' => 'Black Week',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $trackCampaignId = DB::table('stats_track_campaigns')->insertGetId([
        'uuid' => (string) Str::uuid(),
        'team_id' => $this->team->id,
        'name' => 'black-week',
        'campaign_id' => $campaignId,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $time = now()->subDay()->format('Y-m-d').' 12:00:00';

    foreach ([$this->project->id, $otherProject->id, $otherProject->id] as $projectId) {
        DB::table('stats_users')->insert([
            'id' => (string) Str::uuid(),
            'team_id' => $this->team->id,
            'project_id' => $projectId,
            'stats_track_campaign_id' => $trackCampaignId,
            'time' => $time,
            'time_utc' => $time,
        ]);
    }

    SimpleStatsServer::actingAs($this->user)
        ->tool(GetCampaignPerformance::class, ['preset' => 'last_7_days', 'project_id' => $this->project->id])
        ->assertOk()
        ->assertStructuredContent(function ($json): void {
            $json->where('campaigns.0.name', 'Black Week')
                ->where('campaigns.0.reg', fn ($reg): bool => (int) $reg === 1)
                ->etc();
        });
});
