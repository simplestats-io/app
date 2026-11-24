<?php

use App\DTOs\StatsFilterDto;
use App\Enums\StatsRangeType;
use App\Enums\SubscriptionInterval;
use App\Models\AdSpend;
use App\Models\Campaign;
use App\Models\Project;
use App\Models\User;
use App\Services\AdSpendService;
use App\Services\MrrAggregationService;
use Carbon\CarbonImmutable;
use Database\Seeders\TestUserSeeder;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-06-21 12:00:00');

    $this->seed(TestUserSeeder::class);
    $this->user = User::where('email', config('app.test.user')['email'])->firstOrFail();
    $this->project = Project::first();
    $this->team = $this->project->team;
    $this->pid = $this->project->id;

    DB::table('stats_payments')->delete();
    DB::table('stats_users')->delete();
    DB::table('ad_spends')->delete();
    DB::table('stats_track_campaigns')->delete();
    DB::table('campaigns')->delete();

    auth()->setUser($this->user);
    Context::add('team_id', $this->team->id);

    $this->service = resolve(AdSpendService::class);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

function makeCampaign(int $teamId, ?int $projectId, string $name): int
{
    return DB::table('campaigns')->insertGetId([
        'uuid' => (string) Str::uuid(),
        'team_id' => $teamId,
        'project_id' => $projectId,
        'name' => $name,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function makeTrackCampaign(int $teamId, string $name, ?int $campaignId = null): int
{
    return DB::table('stats_track_campaigns')->insertGetId([
        'uuid' => (string) Str::uuid(),
        'team_id' => $teamId,
        'name' => $name,
        'campaign_id' => $campaignId,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function makeCampaignUser(string $id, int $teamId, int $projectId, int $trackCampaignId, string $time): void
{
    DB::table('stats_users')->insert([
        'id' => $id,
        'team_id' => $teamId,
        'project_id' => $projectId,
        'stats_track_campaign_id' => $trackCampaignId,
        'time' => $time,
        'time_utc' => $time,
    ]);
}

function makeCampaignPayment(string $id, string $userId, int $teamId, int $projectId, float $net, string $time): void
{
    DB::table('stats_payments')->insert([
        'id' => $id,
        'stats_user_id' => $userId,
        'team_id' => $teamId,
        'project_id' => $projectId,
        'gross' => $net,
        'net' => $net,
        'currency' => 'EUR',
        'counter' => 1,
        'time' => $time,
        'time_utc' => $time,
    ]);
}

function rangeFilter(int $projectId, int $rangeAmount, string $rangeStart): StatsFilterDto
{
    return new StatsFilterDto(
        projectId: $projectId, rangeAmount: $rangeAmount, rangeType: StatsRangeType::DAY,
        rangeStart: CarbonImmutable::parse($rangeStart), comparison: '0',
    );
}

it('allocates a spend entry proportionally by day overlap', function (): void {
    $spend = new AdSpend(['amount' => 3000, 'starts_on' => '2026-06-01', 'ends_on' => '2026-06-30']);

    // 30-day run, 100 cents/day; window covers 10 of those days
    expect($spend->allocatedFor(CarbonImmutable::parse('2026-06-11'), CarbonImmutable::parse('2026-06-20')))->toBe(1000)
        // window fully contains the run -> whole budget
        ->and($spend->allocatedFor(CarbonImmutable::parse('2026-05-01'), CarbonImmutable::parse('2026-07-01')))->toBe(3000)
        // no overlap -> nothing
        ->and($spend->allocatedFor(CarbonImmutable::parse('2026-07-02'), CarbonImmutable::parse('2026-07-10')))->toBe(0);
});

it('computes roas, cac and net profit per campaign for the range', function (): void {
    $campaign = makeCampaign($this->team->id, $this->pid, 'Google Ads');
    $utm = makeTrackCampaign($this->team->id, 'google_ads', $campaign);
    makeCampaignUser('u1', $this->team->id, $this->pid, $utm, '2026-06-15 10:00:00');
    makeCampaignPayment('p1', 'u1', $this->team->id, $this->pid, 10000, '2026-06-15 10:00:00');

    AdSpend::factory()->create([
        'team_id' => $this->team->id,
        'campaign_id' => $campaign,
        'amount' => 5000,
        'starts_on' => '2026-06-01',
        'ends_on' => '2026-06-20',
    ]);

    $rows = $this->service->getCampaignProfitability(rangeFilter($this->pid, 30, '2026-06-21'));

    expect($rows)->toHaveCount(1);
    expect($rows[0])->toMatchArray([
        'campaign_id' => $campaign,
        'net' => 10000,
        'spend' => 5000,
        'reg' => 1,
        'net_profit' => 5000,
        'roas' => 2.0,
        'cac' => 5000,
    ]);
});

it('lists a campaign with spend but no revenue as a pure loss', function (): void {
    $campaign = makeCampaign($this->team->id, $this->pid, 'Wasted Budget');

    AdSpend::factory()->create([
        'team_id' => $this->team->id,
        'campaign_id' => $campaign,
        'amount' => 3000,
        'starts_on' => '2026-06-01',
        'ends_on' => '2026-06-20',
    ]);

    $rows = $this->service->getCampaignProfitability(rangeFilter($this->pid, 30, '2026-06-21'));

    expect($rows)->toHaveCount(1);
    expect($rows[0])->toMatchArray([
        'campaign_id' => $campaign,
        'net' => 0,
        'spend' => 3000,
        'net_profit' => -3000,
        'roas' => 0.0,
        'cac' => null,
    ]);
});

it('lists a freshly created campaign even without spend or revenue', function (): void {
    $campaign = makeCampaign($this->team->id, $this->pid, 'Pre-launch');

    $rows = $this->service->getCampaignProfitability(rangeFilter($this->pid, 30, '2026-06-21'));

    expect($rows)->toHaveCount(1);
    expect($rows[0])->toMatchArray([
        'campaign_id' => $campaign,
        'net' => 0,
        'spend' => 0,
        'net_profit' => 0,
        'roas' => null,
        'cac' => null,
    ]);
});

it('leaves roas null for revenue without recorded spend', function (): void {
    $campaign = makeCampaign($this->team->id, $this->pid, 'Organic-ish');
    $utm = makeTrackCampaign($this->team->id, 'organic_ish', $campaign);
    makeCampaignUser('u1', $this->team->id, $this->pid, $utm, '2026-06-15 10:00:00');
    makeCampaignPayment('p1', 'u1', $this->team->id, $this->pid, 8000, '2026-06-15 10:00:00');

    $rows = $this->service->getCampaignProfitability(rangeFilter($this->pid, 30, '2026-06-21'));

    expect($rows[0]['roas'])->toBeNull()
        ->and($rows[0]['net_profit'])->toBe(8000);
});

it('attributes revenue to the range in which the paying customer was acquired', function (): void {
    $campaign = makeCampaign($this->team->id, $this->pid, 'Newsletter');
    $utm = makeTrackCampaign($this->team->id, 'newsletter', $campaign);

    // acquired in the range, keeps paying after it: every payment to date counts
    makeCampaignUser('u_new', $this->team->id, $this->pid, $utm, '2026-05-10 10:00:00');
    makeCampaignPayment('p1', 'u_new', $this->team->id, $this->pid, 1000, '2026-05-10 10:00:00');
    makeCampaignPayment('p2', 'u_new', $this->team->id, $this->pid, 1000, '2026-06-10 10:00:00');

    // acquired before the range: its payment inside the range belongs to that earlier range
    makeCampaignUser('u_old', $this->team->id, $this->pid, $utm, '2026-03-01 10:00:00');
    makeCampaignPayment('p3', 'u_old', $this->team->id, $this->pid, 5000, '2026-05-12 10:00:00');

    $rows = $this->service->getCampaignProfitability(rangeFilter($this->pid, 10, '2026-05-15'));

    expect($rows[0])->toMatchArray(['reg' => 1, 'net' => 2000]);
});

it('attributes guest payments by the visit date without double counting user payments', function (): void {
    $campaign = makeCampaign($this->team->id, $this->pid, 'Shop Ads');
    $utm = makeTrackCampaign($this->team->id, 'shop_ads', $campaign);

    $visit = fn (string $hash, string $time): int => DB::table('stats_visitors')->insertGetId([
        'team_id' => $this->team->id,
        'project_id' => $this->pid,
        'visitor_hash' => $hash,
        'stats_track_campaign_id' => $utm,
        'time' => $time,
        'time_utc' => $time,
    ]);

    $guest = $visit('guest', '2026-06-15 10:00:00');
    $registered = $visit('registered', '2026-06-15 11:00:00');
    $earlier = $visit('earlier', '2026-04-01 10:00:00');

    $payment = fn (string $id, ?string $userId, int $visitorId, int $net): bool => DB::table('stats_payments')->insert([
        'id' => $id, 'stats_user_id' => $userId, 'stats_visitor_id' => $visitorId, 'team_id' => $this->team->id,
        'project_id' => $this->pid, 'gross' => $net, 'net' => $net, 'currency' => 'EUR', 'counter' => 1,
        'time' => '2026-06-16 10:00:00', 'time_utc' => '2026-06-16 10:00:00',
    ]);

    makeCampaignUser('u1', $this->team->id, $this->pid, $utm, '2026-06-15 11:05:00');
    $payment('p_guest', null, $guest, 3000);
    $payment('p_user', 'u1', $registered, 2000);
    $payment('p_earlier', null, $earlier, 9000);

    $rows = $this->service->getCampaignProfitability(rangeFilter($this->pid, 30, '2026-06-21'));

    expect($rows[0])->toMatchArray(['visitors' => 2, 'reg' => 1, 'net' => 5000]);
});

it('leaves cac and payback null for registrations without spend in the range', function (): void {
    $this->project->update(['usage' => Project::USAGE_VISITORS | Project::USAGE_USERS | Project::USAGE_PAYMENTS | Project::USAGE_SUBSCRIPTION]);

    $campaign = makeCampaign($this->team->id, $this->pid, 'Finished Campaign');
    $utm = makeTrackCampaign($this->team->id, 'finished', $campaign);
    makeCampaignUser('u1', $this->team->id, $this->pid, $utm, '2026-06-15 10:00:00');

    AdSpend::factory()->create([
        'team_id' => $this->team->id,
        'campaign_id' => $campaign,
        'amount' => 5000,
        'starts_on' => '2026-01-01',
        'ends_on' => '2026-01-31',
    ]);

    $rows = $this->service->getCampaignProfitability(rangeFilter($this->pid, 30, '2026-06-21'));

    expect($rows[0])->toMatchArray(['reg' => 1, 'spend' => 0, 'cac' => null, 'cac_payback' => null, 'has_activity' => true]);
});

it('bundles revenue from multiple linked utm campaigns and sums spend entries', function (): void {
    $campaign = makeCampaign($this->team->id, $this->pid, 'Meta Ads');
    $utmA = makeTrackCampaign($this->team->id, 'meta_ads_a', $campaign);
    $utmB = makeTrackCampaign($this->team->id, 'meta_ads_b', $campaign);
    makeCampaignUser('u1', $this->team->id, $this->pid, $utmA, '2026-06-15 10:00:00');
    makeCampaignPayment('p1', 'u1', $this->team->id, $this->pid, 12000, '2026-06-15 10:00:00');
    makeCampaignUser('u2', $this->team->id, $this->pid, $utmB, '2026-06-16 10:00:00');
    makeCampaignPayment('p2', 'u2', $this->team->id, $this->pid, 8000, '2026-06-16 10:00:00');

    AdSpend::factory()->create([
        'team_id' => $this->team->id, 'campaign_id' => $campaign,
        'amount' => 4000, 'starts_on' => '2026-06-01', 'ends_on' => '2026-06-10',
    ]);
    AdSpend::factory()->create([
        'team_id' => $this->team->id, 'campaign_id' => $campaign,
        'amount' => 6000, 'starts_on' => '2026-06-11', 'ends_on' => '2026-06-20',
    ]);

    $rows = $this->service->getCampaignProfitability(rangeFilter($this->pid, 30, '2026-06-21'));

    expect($rows[0]['net'])->toBe(20000)
        ->and($rows[0]['spend'])->toBe(10000)
        ->and($rows[0]['roas'])->toBe(2.0);
});

function enableSubscriptionTracking(Project $project): void
{
    $project->update(['usage' => $project->usage | Project::USAGE_SUBSCRIPTION]);
}

function makeSubscriptionPayment(string $id, string $userId, int $teamId, int $projectId, float $net, string $time): void
{
    DB::table('stats_payments')->insert([
        'id' => $id,
        'stats_user_id' => $userId,
        'team_id' => $teamId,
        'project_id' => $projectId,
        'gross' => $net,
        'net' => $net,
        'currency' => 'EUR',
        'subscription_interval' => SubscriptionInterval::MONTH->value,
        'counter' => 1,
        'time' => $time,
        'time_utc' => $time,
    ]);
}

it('computes ltv:cac and cac payback for a subscription campaign', function (): void {
    enableSubscriptionTracking($this->project);

    $campaign = makeCampaign($this->team->id, $this->pid, 'Sub Ads');
    $utm = makeTrackCampaign($this->team->id, 'sub_ads', $campaign);

    // Registered in-range (drives reg/cac); their payment chains reach back before
    // the range so the MRR snapshot has a starting MRR and observable churn.
    makeCampaignUser('u-stable', $this->team->id, $this->pid, $utm, '2026-06-10 10:00:00');
    makeCampaignUser('u-churn', $this->team->id, $this->pid, $utm, '2026-06-10 10:00:00');

    // Reaches back before the 3-month economics window so its starting (baseline)
    // bucket carries MRR and a churn rate can be derived.
    makeSubscriptionPayment('s-0', 'u-stable', $this->team->id, $this->pid, 100, '2026-03-15 10:00:00');
    makeSubscriptionPayment('s-1', 'u-stable', $this->team->id, $this->pid, 100, '2026-04-15 10:00:00');
    makeSubscriptionPayment('s-2', 'u-stable', $this->team->id, $this->pid, 100, '2026-05-15 10:00:00');
    makeSubscriptionPayment('s-3', 'u-stable', $this->team->id, $this->pid, 100, '2026-06-15 10:00:00');
    makeSubscriptionPayment('c-1', 'u-churn', $this->team->id, $this->pid, 40, '2026-04-15 10:00:00');

    AdSpend::factory()->create([
        'team_id' => $this->team->id, 'campaign_id' => $campaign,
        'amount' => 200, 'starts_on' => '2026-04-01', 'ends_on' => '2026-06-30',
    ]);

    $filter = new StatsFilterDto(
        projectId: $this->pid, rangeAmount: 2, rangeType: StatsRangeType::MONTH,
        rangeStart: CarbonImmutable::parse('2026-06-21'), comparison: '0',
    );

    $row = $this->service->getCampaignProfitability($filter)[0];

    // The economics half is measured over the fixed trailing window (3 months from
    // now, campaign-scoped), not the UI range.
    $economicsFilter = new StatsFilterDto(
        projectId: $this->pid, rangeAmount: 3, rangeType: StatsRangeType::MONTH,
        rangeStart: now(), comparison: '0', campaign: $campaign,
    );
    $economics = resolve(MrrAggregationService::class)->getSubscriptionEconomics($economicsFilter);

    expect($row['ltv_cac'])->toBe(round($economics['ltv'] / $row['cac'], 2))
        ->and($row['cac_payback'])->toBe(round($row['cac'] / $economics['arps'], 1));
});

it('keeps the economics half of ltv:cac and payback independent of the ui range', function (): void {
    enableSubscriptionTracking($this->project);

    $campaign = makeCampaign($this->team->id, $this->pid, 'Stable Econ');
    $utm = makeTrackCampaign($this->team->id, 'stable_econ', $campaign);

    // Both users register in June, so the CAC is identical across the two ranges
    // below; their payment chains reach back so the fixed window observes churn.
    makeCampaignUser('u-stable', $this->team->id, $this->pid, $utm, '2026-06-07 10:00:00');
    makeCampaignUser('u-churn', $this->team->id, $this->pid, $utm, '2026-06-07 10:00:00');

    makeSubscriptionPayment('s-0', 'u-stable', $this->team->id, $this->pid, 100, '2026-03-15 10:00:00');
    makeSubscriptionPayment('s-1', 'u-stable', $this->team->id, $this->pid, 100, '2026-04-15 10:00:00');
    makeSubscriptionPayment('s-2', 'u-stable', $this->team->id, $this->pid, 100, '2026-05-15 10:00:00');
    makeSubscriptionPayment('s-3', 'u-stable', $this->team->id, $this->pid, 100, '2026-06-15 10:00:00');
    makeSubscriptionPayment('c-1', 'u-churn', $this->team->id, $this->pid, 40, '2026-04-15 10:00:00');

    // Fully contained in both ranges, so the allocated spend is identical too.
    AdSpend::factory()->create([
        'team_id' => $this->team->id, 'campaign_id' => $campaign,
        'amount' => 400, 'starts_on' => '2026-06-01', 'ends_on' => '2026-06-30',
    ]);

    $shortRange = new StatsFilterDto(
        projectId: $this->pid, rangeAmount: 1, rangeType: StatsRangeType::MONTH,
        rangeStart: CarbonImmutable::parse('2026-06-21'), comparison: '0',
    );
    $longRange = new StatsFilterDto(
        projectId: $this->pid, rangeAmount: 6, rangeType: StatsRangeType::MONTH,
        rangeStart: CarbonImmutable::parse('2026-06-21'), comparison: '0',
    );

    $short = $this->service->getCampaignProfitability($shortRange)[0];
    $long = $this->service->getCampaignProfitability($longRange)[0];

    expect($short['cac'])->toBe($long['cac'])
        ->and($short['ltv_cac'])->not->toBeNull()
        ->and($short['ltv_cac'])->toBe($long['ltv_cac'])
        ->and($short['cac_payback'])->not->toBeNull()
        ->and($short['cac_payback'])->toBe($long['cac_payback']);
});

it('leaves ltv:cac and payback null when the project does not track subscriptions', function (): void {
    $campaign = makeCampaign($this->team->id, $this->pid, 'No Sub');
    $utm = makeTrackCampaign($this->team->id, 'no_sub', $campaign);
    makeCampaignUser('u1', $this->team->id, $this->pid, $utm, '2026-06-15 10:00:00');
    makeCampaignPayment('p1', 'u1', $this->team->id, $this->pid, 10000, '2026-06-15 10:00:00');

    AdSpend::factory()->create([
        'team_id' => $this->team->id, 'campaign_id' => $campaign,
        'amount' => 5000, 'starts_on' => '2026-06-01', 'ends_on' => '2026-06-20',
    ]);

    $row = $this->service->getCampaignProfitability(rangeFilter($this->pid, 30, '2026-06-21'))[0];

    expect($row['cac'])->toBe(5000)
        ->and($row['ltv_cac'])->toBeNull()
        ->and($row['cac_payback'])->toBeNull();
});

it('leaves ltv:cac and payback null when the campaign has no cac', function (): void {
    enableSubscriptionTracking($this->project);

    $campaign = makeCampaign($this->team->id, $this->pid, 'Spend Only');
    AdSpend::factory()->create([
        'team_id' => $this->team->id, 'campaign_id' => $campaign,
        'amount' => 3000, 'starts_on' => '2026-06-01', 'ends_on' => '2026-06-20',
    ]);

    $row = $this->service->getCampaignProfitability(rangeFilter($this->pid, 30, '2026-06-21'))[0];

    expect($row['cac'])->toBeNull()
        ->and($row['ltv_cac'])->toBeNull()
        ->and($row['cac_payback'])->toBeNull();
});

it('keeps a payback while leaving ltv:cac null for a churn-free campaign', function (): void {
    enableSubscriptionTracking($this->project);

    $campaign = makeCampaign($this->team->id, $this->pid, 'Sticky Ads');
    $utm = makeTrackCampaign($this->team->id, 'sticky_ads', $campaign);

    makeCampaignUser('u-stable', $this->team->id, $this->pid, $utm, '2026-06-10 10:00:00');
    makeSubscriptionPayment('s-1', 'u-stable', $this->team->id, $this->pid, 100, '2026-04-15 10:00:00');
    makeSubscriptionPayment('s-2', 'u-stable', $this->team->id, $this->pid, 100, '2026-05-15 10:00:00');
    makeSubscriptionPayment('s-3', 'u-stable', $this->team->id, $this->pid, 100, '2026-06-15 10:00:00');

    AdSpend::factory()->create([
        'team_id' => $this->team->id, 'campaign_id' => $campaign,
        'amount' => 200, 'starts_on' => '2026-04-01', 'ends_on' => '2026-06-30',
    ]);

    $filter = new StatsFilterDto(
        projectId: $this->pid, rangeAmount: 2, rangeType: StatsRangeType::MONTH,
        rangeStart: CarbonImmutable::parse('2026-06-21'), comparison: '0',
    );

    $row = $this->service->getCampaignProfitability($filter)[0];

    expect($row['ltv_cac'])->toBeNull()
        ->and($row['cac_payback'])->not->toBeNull();
});

it('paginates campaigns and only computes kpis for the visible page', function (): void {
    enableSubscriptionTracking($this->project);

    foreach (range(1, 25) as $i) {
        makeCampaign($this->team->id, $this->pid, "Campaign {$i}");
    }

    $paginator = $this->service->getCampaignProfitability(rangeFilter($this->pid, 30, '2026-06-21'), 20);

    expect($paginator)->toBeInstanceOf(LengthAwarePaginator::class)
        ->and($paginator->total())->toBe(25)
        ->and($paginator->perPage())->toBe(20)
        ->and($paginator->count())->toBe(20);
});

it('counts every project for a campaign for all projects and only its own project otherwise', function (): void {
    $otherProject = Project::factory()->create(['team_id' => $this->team->id]);
    $teamWide = makeCampaign($this->team->id, null, 'Black Friday');
    $projectOnly = makeCampaign($this->team->id, $this->pid, 'Spring Sale');
    $bf = makeTrackCampaign($this->team->id, 'bf', $teamWide);
    $spring = makeTrackCampaign($this->team->id, 'spring', $projectOnly);

    foreach ([['u1', $this->pid, $bf, 1000], ['u2', $otherProject->id, $bf, 3000], ['u3', $this->pid, $spring, 500], ['u4', $otherProject->id, $spring, 7000]] as [$userId, $projectId, $utm, $net]) {
        makeCampaignUser($userId, $this->team->id, $projectId, $utm, '2026-06-15 10:00:00');
        makeCampaignPayment("p-{$userId}", $userId, $this->team->id, $projectId, $net, '2026-06-15 10:00:00');
    }

    $rows = collect($this->service->getCampaignProfitability(rangeFilter(StatsFilterDto::TRACK_ALL, 30, '2026-06-21'))->items())->keyBy('name');

    expect($rows['Black Friday'])->toMatchArray(['reg' => 2, 'net' => 4000, 'project_id' => null, 'project_uuid' => null, 'project_name' => 'All projects'])
        ->and($rows['Spring Sale'])->toMatchArray(['reg' => 1, 'net' => 500, 'project_id' => $this->pid]);

    expect(collect($this->service->getCampaignProfitability(rangeFilter($this->pid, 30, '2026-06-21'))->items())->pluck('name')->all())
        ->toBe(['Spring Sale']);
});

it('shows a member limited to selected projects only their project campaigns and numbers', function (): void {
    $otherProject = Project::factory()->create(['team_id' => $this->team->id]);
    makeTrackCampaign($this->team->id, 'bf', makeCampaign($this->team->id, null, 'Black Friday'));
    makeCampaign($this->team->id, $otherProject->id, 'Blog Sale');
    $spring = makeTrackCampaign($this->team->id, 'spring', makeCampaign($this->team->id, $this->pid, 'Spring Sale'));
    makeCampaignUser('u1', $this->team->id, $this->pid, $spring, '2026-06-15 10:00:00');

    $member = User::factory()->create();
    $this->team->users()->attach($member, ['role' => 'editor', 'project_ids' => [$this->pid]]);
    $member->switchTeam($this->team);
    auth()->setUser($member->fresh());

    $rows = collect($this->service->getCampaignProfitability(rangeFilter($this->pid, 30, '2026-06-21'))->items());

    expect($rows->pluck('name')->all())->toBe(['Spring Sale'])
        ->and($rows->first()['reg'])->toBe(1)
        ->and($this->service->getCampaignProfitability(rangeFilter(StatsFilterDto::TRACK_ALL, 30, '2026-06-21'))->items())
        ->each->not->toHaveKey('name', 'Black Friday');
});

function makeCampaignVisitor(int $teamId, int $projectId, int $trackCampaignId, ?int $sourceId, string $time, ?int $views = null): void
{
    $visitorId = DB::table('stats_visitors')->insertGetId([
        'team_id' => $teamId,
        'project_id' => $projectId,
        'visitor_hash' => (string) Str::uuid(),
        'stats_track_campaign_id' => $trackCampaignId,
        'stats_track_source_id' => $sourceId,
        'time' => $time,
        'time_utc' => $time,
    ]);

    if ($views !== null) {
        DB::table('stats_visitor_views')->insert([
            'stats_visitor_id' => $visitorId,
            'team_id' => $teamId,
            'project_id' => $projectId,
            'time' => $time,
            'last_viewed_at' => $time,
            'views' => $views,
        ]);
    }
}

function makeCampaignSource(int $teamId, string $name): int
{
    return DB::table('stats_track_sources')->insertGetId([
        'team_id' => $teamId,
        'name' => $name,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

it('splits the campaign cohort per utm_source with bounce rates over viewers', function (): void {
    $this->project->forceFill(['page_views_seen_at' => now()])->save();

    $campaign = makeCampaign($this->team->id, $this->pid, 'Black Week');
    $utm = makeTrackCampaign($this->team->id, 'black_week', $campaign);
    $google = makeCampaignSource($this->team->id, 'google');
    $meta = makeCampaignSource($this->team->id, 'meta');

    makeCampaignVisitor($this->team->id, $this->pid, $utm, $google, '2026-06-18 10:00:00', 1);
    makeCampaignVisitor($this->team->id, $this->pid, $utm, $google, '2026-06-19 10:00:00', 3);
    makeCampaignVisitor($this->team->id, $this->pid, $utm, $meta, '2026-06-19 11:00:00', 1);
    makeCampaignVisitor($this->team->id, $this->pid, $utm, null, '2026-06-20 11:00:00');

    DB::table('stats_users')->insert([
        'id' => 'u_google', 'team_id' => $this->team->id, 'project_id' => $this->pid,
        'stats_track_campaign_id' => $utm, 'stats_track_source_id' => $google,
        'time' => '2026-06-19 10:05:00', 'time_utc' => '2026-06-19 10:05:00',
    ]);
    makeCampaignPayment('p1', 'u_google', $this->team->id, $this->pid, 5000, '2026-06-19 10:10:00');

    // Same utm dimension, but another project of the team and another team: neither counts.
    $otherProject = Project::factory()->create(['team_id' => $this->team->id]);
    makeCampaignVisitor($this->team->id, $otherProject->id, $utm, $google, '2026-06-19 10:00:00', 1);
    $foreignTeam = User::factory()->withPersonalTeam()->create()->currentTeam;
    $foreignProject = Project::factory()->create(['team_id' => $foreignTeam->id]);
    makeCampaignVisitor($foreignTeam->id, $foreignProject->id, $utm, $google, '2026-06-19 10:00:00', 1);

    $traffic = $this->service->campaignTraffic(rangeFilter($this->pid, 7, '2026-06-21'), Campaign::findOrFail($campaign));

    expect($traffic['page_views'])->toBeTrue()
        ->and($traffic['bounce_rate'])->toBe(66.7)
        ->and($traffic['sources'])->toBe([
            ['name' => 'google', 'visitors' => 2, 'reg' => 1, 'bounce_rate' => 50.0, 'net' => 5000],
            ['name' => 'meta', 'visitors' => 1, 'reg' => 0, 'bounce_rate' => 100.0, 'net' => 0],
            ['name' => null, 'visitors' => 1, 'reg' => 0, 'bounce_rate' => null, 'net' => 0],
        ]);
});

it('omits bounce rates for a project without recent page views', function (): void {
    $this->project->forceFill(['page_views_seen_at' => null])->save();

    $campaign = makeCampaign($this->team->id, $this->pid, 'No Views');
    $utm = makeTrackCampaign($this->team->id, 'no_views', $campaign);
    makeCampaignVisitor($this->team->id, $this->pid, $utm, null, '2026-06-19 10:00:00', 1);

    $traffic = $this->service->campaignTraffic(rangeFilter($this->pid, 7, '2026-06-21'), Campaign::findOrFail($campaign));

    expect($traffic['page_views'])->toBeFalse()
        ->and($traffic['bounce_rate'])->toBeNull()
        ->and($traffic['sources'][0]['bounce_rate'])->toBeNull();
});

it('reports the total recorded spend and the cost per visitor next to the range share', function (): void {
    $campaign = makeCampaign($this->team->id, $this->pid, 'Partial Range');
    $utm = makeTrackCampaign($this->team->id, 'partial_range', $campaign);
    makeCampaignVisitor($this->team->id, $this->pid, $utm, null, '2026-06-19 10:00:00');
    makeCampaignVisitor($this->team->id, $this->pid, $utm, null, '2026-06-20 10:00:00');

    foreach ([11500, 5000] as $amount) {
        AdSpend::factory()->create([
            'team_id' => $this->team->id,
            'campaign_id' => $campaign,
            'amount' => $amount,
            'starts_on' => '2026-06-14',
            'ends_on' => '2026-06-21',
        ]);
    }

    $summary = $this->service->campaignSummary(rangeFilter($this->pid, 7, '2026-06-21'), Campaign::findOrFail($campaign));

    // 7 of 8 run days fall into the range
    expect($summary)->toMatchArray([
        'spend' => 14438,
        'spend_total' => 16500,
        'visitors' => 2,
        'cost_per_visitor' => 7219.0,
    ]);
});
