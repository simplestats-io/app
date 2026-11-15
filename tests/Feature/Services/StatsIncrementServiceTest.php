<?php

use App\DTOs\StatsFilterDto;
use App\Enums\StatsRangeType;
use App\Models\Project;
use App\Models\StatsAggregation;
use App\Models\StatsLogin;
use App\Models\StatsPayment;
use App\Models\StatsUser;
use App\Models\StatsVisitor;
use App\Models\User;
use App\Observers\StatsObserver;
use App\Services\StatsAggregationsService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Database\Seeders\TestUserSeeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    $this->seed(TestUserSeeder::class);
    $this->user = User::where('email', config('app.test.user')['email'])->firstOrFail();
    $this->project = Project::first();
    $this->team = $this->project->team;
    $this->team->update(['timezone' => 'UTC']);
    $this->pid = $this->project->id;

    DB::table('stats_custom_events')->delete();
    DB::table('stats_payments')->delete();
    DB::table('stats_logins')->delete();
    DB::table('stats_visitors')->delete();
    DB::table('stats_users')->delete();

    auth()->setUser($this->user);
    Context::add('team_id', $this->team->id);
    config(['app.stats_aggregation_enabled' => true]);

    $this->now = '2026-06-14 12:00:00';
    Carbon::setTestNow(Carbon::parse($this->now));

    $this->service = resolve(StatsAggregationsService::class);
    $this->dayFilter = fn (array $extra = []): StatsFilterDto => new StatsFilterDto(...array_merge([
        'projectId' => $this->pid,
        'rangeAmount' => 1,
        'rangeType' => StatsRangeType::DAY,
        'rangeStart' => CarbonImmutable::parse($this->now),
        'comparison' => '0',
    ], $extra));
});

afterEach(function (): void {
    StatsObserver::$enabled = true;
    Carbon::setTestNow();
});

/** Null out every tracking dimension so created stats are deterministic. */
function nullDims(): array
{
    return [
        'location_country' => null, 'location_region' => null, 'location_city' => null,
        'device_type' => null, 'device_platform' => null, 'device_browser' => null,
        'track_referer' => null, 'track_source' => null, 'track_medium' => null,
        'track_campaign' => null, 'track_term' => null, 'track_content' => null,
        'page_entry' => null,
    ];
}

function browserId(string $name): int
{
    return (int) DB::table('stats_device_browsers')->where('name', $name)->value('id');
}

function openDayBucketIsCached(StatsFilterDto $filters): bool
{
    return StatsAggregation::where('filter_hash', $filters->makeHash())->where('time', '2026-06-14')->exists();
}

/** Build aggregation cache for the given filters without recomputing afterwards. */
function freshlyRecomputed(StatsAggregationsService $service, StatsFilterDto $filters): Collection
{
    StatsAggregation::query()->delete();

    return $service->getAggregatedData($filters);
}

it('increments the open day bucket for a new visitor, unfiltered and filtered, leaving non-matching filters untouched', function (): void {
    StatsObserver::$enabled = false;
    StatsVisitor::factory()->count(5)->create(array_merge(nullDims(), [
        'team_id' => $this->team->id, 'project_id' => $this->pid, 'device_browser' => 'Chrome', 'time' => $this->now,
    ]));
    StatsVisitor::factory()->count(3)->create(array_merge(nullDims(), [
        'team_id' => $this->team->id, 'project_id' => $this->pid, 'device_browser' => 'Safari', 'time' => $this->now,
    ]));
    StatsObserver::$enabled = true;

    $chrome = browserId('Chrome');
    $safari = browserId('Safari');

    $unfiltered = ($this->dayFilter)();
    $chromeFilter = ($this->dayFilter)(['deviceBrowser' => $chrome]);
    $safariFilter = ($this->dayFilter)(['deviceBrowser' => $safari]);

    // seed all three caches
    expect($this->service->getAggregatedData($unfiltered)['2026-06-14']['pd_visitor'])->toBe(8);
    $this->service->getAggregatedData($chromeFilter);
    $this->service->getAggregatedData($safariFilter);

    // new Chrome visitor triggers the incremental observer
    StatsVisitor::factory()->create(array_merge(nullDims(), [
        'team_id' => $this->team->id, 'project_id' => $this->pid, 'device_browser' => 'Chrome', 'time' => $this->now,
    ]));

    $unfilteredRow = $this->service->getAggregatedData($unfiltered)['2026-06-14'];
    $chromeRow = $this->service->getAggregatedData($chromeFilter)['2026-06-14'];

    expect($unfilteredRow['pd_visitor'])->toBe(9)
        ->and($chromeRow['pd_visitor'])->toBe(6)
        ->and($this->service->getAggregatedData($safariFilter)['2026-06-14']['pd_visitor'])->toBe(3)
        // lifetime visitor count is incremented too (all visitors are in today's bucket)
        ->and($unfilteredRow['lt_visitor'])->toBe(9)
        ->and($chromeRow['lt_visitor'])->toBe(6);

    // parity: incrementally maintained values match a full recompute
    $unfilteredFresh = freshlyRecomputed($this->service, $unfiltered)['2026-06-14'];
    expect($unfilteredFresh['pd_visitor'])->toBe(9)
        ->and($unfilteredFresh['lt_visitor'])->toBe(9)
        ->and(freshlyRecomputed($this->service, $chromeFilter)['2026-06-14']['pd_visitor'])->toBe(6);
});

it('increments the organic (-1) filtered bucket for a visitor without that dimension', function (): void {
    StatsObserver::$enabled = false;
    StatsVisitor::factory()->count(2)->create(array_merge(nullDims(), [
        'team_id' => $this->team->id, 'project_id' => $this->pid, 'device_browser' => null, 'time' => $this->now,
    ]));
    StatsObserver::$enabled = true;

    $organicFilter = ($this->dayFilter)(['deviceBrowser' => StatsFilterDto::TRACK_ORGANIC]);
    $this->service->getAggregatedData($organicFilter);

    StatsVisitor::factory()->create(array_merge(nullDims(), [
        'team_id' => $this->team->id, 'project_id' => $this->pid, 'device_browser' => null, 'time' => $this->now,
    ]));

    expect($this->service->getAggregatedData($organicFilter)['2026-06-14']['pd_visitor'])->toBe(3)
        ->and(freshlyRecomputed($this->service, $organicFilter)['2026-06-14']['pd_visitor'])->toBe(3);
});

it('increments registrations on the open bucket', function (): void {
    StatsObserver::$enabled = false;
    StatsUser::factory()->count(4)->create(array_merge(nullDims(), [
        'team_id' => $this->team->id, 'project_id' => $this->pid, 'time' => $this->now,
    ]));
    StatsObserver::$enabled = true;

    $filter = ($this->dayFilter)();
    expect($this->service->getAggregatedData($filter)['2026-06-14']['pd_reg'])->toBe(4);

    StatsUser::factory()->create(array_merge(nullDims(), [
        'team_id' => $this->team->id, 'project_id' => $this->pid, 'time' => $this->now,
    ]));

    expect($this->service->getAggregatedData($filter)['2026-06-14']['pd_reg'])->toBe(5)
        ->and(freshlyRecomputed($this->service, $filter)['2026-06-14']['pd_reg'])->toBe(5);
});

it('increments active-user metrics for a login by a non-paying user', function (): void {
    StatsObserver::$enabled = false;
    $user = StatsUser::factory()->create(array_merge(nullDims(), [
        'team_id' => $this->team->id, 'project_id' => $this->pid, 'time' => $this->now,
    ]));
    // the registration login (counter is auto-computed to 1)
    StatsLogin::factory()->create([
        'stats_user_id' => $user->id, 'team_id' => $this->team->id, 'project_id' => $this->pid, 'time' => $this->now,
    ]);
    StatsObserver::$enabled = true;

    $filter = ($this->dayFilter)();
    $this->service->getAggregatedData($filter);

    // a returning login (counter is auto-computed to 2)
    StatsLogin::factory()->create([
        'stats_user_id' => $user->id, 'team_id' => $this->team->id, 'project_id' => $this->pid, 'time' => $this->now,
    ]);

    $data = $this->service->getAggregatedData($filter)['2026-06-14'];
    expect($data['pd_tau'])->toBe(1)
        ->and($data['pd_nau'])->toBe(1)
        ->and($data['pd_dau'])->toBe(1)
        ->and($data['lt_tau'])->toBe(1);

    $fresh = freshlyRecomputed($this->service, $filter)['2026-06-14'];
    expect($data['pd_tau'])->toBe($fresh['pd_tau'])
        ->and($data['pd_dau'])->toBe($fresh['pd_dau'])
        ->and($data['lt_tau'])->toBe($fresh['lt_tau']);
});

it('does not double-count dau for a second login of the same user in the bucket', function (): void {
    StatsObserver::$enabled = false;
    $user = StatsUser::factory()->create(array_merge(nullDims(), [
        'team_id' => $this->team->id, 'project_id' => $this->pid, 'time' => $this->now,
    ]));
    // registration login (counter auto = 1)
    StatsLogin::factory()->create([
        'stats_user_id' => $user->id, 'team_id' => $this->team->id, 'project_id' => $this->pid, 'time' => $this->now,
    ]);
    StatsObserver::$enabled = true;

    $filter = ($this->dayFilter)();
    $this->service->getAggregatedData($filter);

    // two returning logins of the SAME user on the same day (counter auto = 2, then 3)
    StatsLogin::factory()->create([
        'stats_user_id' => $user->id, 'team_id' => $this->team->id, 'project_id' => $this->pid, 'time' => $this->now,
    ]);
    StatsLogin::factory()->create([
        'stats_user_id' => $user->id, 'team_id' => $this->team->id, 'project_id' => $this->pid, 'time' => $this->now,
    ]);

    $data = $this->service->getAggregatedData($filter)['2026-06-14'];
    // dau is a DISTINCT user count: the second login must NOT increment it again.
    // pd_tau/pd_nau count only counter == NAU (2), so the counter-3 login adds nothing there either.
    expect($data['pd_dau'])->toBe(1)
        ->and($data['pd_tau'])->toBe(1);

    $fresh = freshlyRecomputed($this->service, $filter)['2026-06-14'];
    expect($data['pd_dau'])->toBe($fresh['pd_dau'])
        ->and($data['pd_tau'])->toBe($fresh['pd_tau']);
});

it('increments dapu and daapu for a login by a user who paid within the daapu window', function (): void {
    StatsObserver::$enabled = false;
    $user = StatsUser::factory()->create(array_merge(nullDims(), [
        'team_id' => $this->team->id, 'project_id' => $this->pid, 'device_browser' => 'Chrome', 'time' => $this->now,
    ]));
    StatsPayment::factory()->create([
        'stats_user_id' => $user->id, 'team_id' => $this->team->id, 'project_id' => $this->pid,
        'time' => '2026-06-10 12:00:00', 'counter' => 1, 'gross' => 100, 'net' => 80,
    ]);
    StatsObserver::$enabled = true;

    $unfiltered = ($this->dayFilter)();
    $chromeFilter = ($this->dayFilter)(['deviceBrowser' => browserId('Chrome')]);
    $this->service->getAggregatedData($unfiltered);
    $this->service->getAggregatedData($chromeFilter);

    StatsLogin::factory()->create([
        'stats_user_id' => $user->id, 'team_id' => $this->team->id, 'project_id' => $this->pid, 'time' => $this->now,
    ]);

    expect(openDayBucketIsCached($unfiltered))->toBeTrue();

    $row = $this->service->getAggregatedData($unfiltered)['2026-06-14'];
    $chromeRow = $this->service->getAggregatedData($chromeFilter)['2026-06-14'];
    expect($row['pd_dapu'])->toBe(1)
        ->and($row['pd_daapu'])->toBe(1)
        ->and($chromeRow['pd_dapu'])->toBe(1)
        ->and($chromeRow['pd_daapu'])->toBe(1);

    $fresh = freshlyRecomputed($this->service, $unfiltered)['2026-06-14'];
    expect($row['pd_dapu'])->toBe($fresh['pd_dapu'])
        ->and($row['pd_daapu'])->toBe($fresh['pd_daapu'])
        ->and($row['pd_dau'])->toBe($fresh['pd_dau']);
});

it('increments only dapu for a login by a user whose last payment is outside the daapu window', function (): void {
    StatsObserver::$enabled = false;
    $user = StatsUser::factory()->create(array_merge(nullDims(), [
        'team_id' => $this->team->id, 'project_id' => $this->pid, 'time' => $this->now,
    ]));
    StatsPayment::factory()->create([
        'stats_user_id' => $user->id, 'team_id' => $this->team->id, 'project_id' => $this->pid,
        'time' => '2026-05-20 12:00:00', 'counter' => 1, 'gross' => 100, 'net' => 80,
    ]);
    StatsObserver::$enabled = true;

    $filter = ($this->dayFilter)();
    $this->service->getAggregatedData($filter);

    StatsLogin::factory()->create([
        'stats_user_id' => $user->id, 'team_id' => $this->team->id, 'project_id' => $this->pid, 'time' => $this->now,
    ]);

    expect(openDayBucketIsCached($filter))->toBeTrue();

    $row = $this->service->getAggregatedData($filter)['2026-06-14'];
    expect($row['pd_dapu'])->toBe(1)
        ->and($row['pd_daapu'])->toBe(0);

    $fresh = freshlyRecomputed($this->service, $filter)['2026-06-14'];
    expect($row['pd_dapu'])->toBe($fresh['pd_dapu'])
        ->and($row['pd_daapu'])->toBe($fresh['pd_daapu']);
});

it('increments daapu for a later login in the bucket once an earlier one fell outside the window', function (): void {
    // The morning login came 25 days after the last payment, so it did not count for daapu.
    // A payment at 10:00 (cached already) brings the user back into the window, so the
    // noon login is the first daapu login of the day, while dapu was already counted.
    StatsObserver::$enabled = false;
    $user = StatsUser::factory()->create(array_merge(nullDims(), [
        'team_id' => $this->team->id, 'project_id' => $this->pid, 'time' => $this->now,
    ]));
    StatsPayment::factory()->create([
        'id' => 'old-pay', 'stats_user_id' => $user->id, 'team_id' => $this->team->id, 'project_id' => $this->pid,
        'time' => '2026-05-20 12:00:00', 'counter' => 1, 'gross' => 100, 'net' => 80,
    ]);
    StatsLogin::factory()->create([
        'stats_user_id' => $user->id, 'team_id' => $this->team->id, 'project_id' => $this->pid, 'time' => '2026-06-14 09:00:00',
    ]);
    StatsPayment::factory()->create([
        'id' => 'new-pay', 'stats_user_id' => $user->id, 'team_id' => $this->team->id, 'project_id' => $this->pid,
        'time' => '2026-06-14 10:00:00', 'counter' => 2, 'gross' => 50, 'net' => 40,
    ]);
    StatsObserver::$enabled = true;

    $filter = ($this->dayFilter)();
    $before = $this->service->getAggregatedData($filter)['2026-06-14'];
    expect($before['pd_dapu'])->toBe(1)
        ->and($before['pd_daapu'])->toBe(0);

    StatsLogin::factory()->create([
        'stats_user_id' => $user->id, 'team_id' => $this->team->id, 'project_id' => $this->pid, 'time' => $this->now,
    ]);

    expect(openDayBucketIsCached($filter))->toBeTrue();

    $row = $this->service->getAggregatedData($filter)['2026-06-14'];
    expect($row['pd_dapu'])->toBe(1)
        ->and($row['pd_daapu'])->toBe(1);

    $fresh = freshlyRecomputed($this->service, $filter)['2026-06-14'];
    expect($row['pd_dapu'])->toBe($fresh['pd_dapu'])
        ->and($row['pd_daapu'])->toBe($fresh['pd_daapu']);
});

it('does not double-count dapu and daapu for a second qualifying login in the bucket', function (): void {
    StatsObserver::$enabled = false;
    $user = StatsUser::factory()->create(array_merge(nullDims(), [
        'team_id' => $this->team->id, 'project_id' => $this->pid, 'time' => $this->now,
    ]));
    StatsPayment::factory()->create([
        'stats_user_id' => $user->id, 'team_id' => $this->team->id, 'project_id' => $this->pid,
        'time' => '2026-06-10 12:00:00', 'counter' => 1, 'gross' => 100, 'net' => 80,
    ]);
    StatsLogin::factory()->create([
        'stats_user_id' => $user->id, 'team_id' => $this->team->id, 'project_id' => $this->pid, 'time' => '2026-06-14 09:00:00',
    ]);
    StatsObserver::$enabled = true;

    $filter = ($this->dayFilter)();
    $this->service->getAggregatedData($filter);

    StatsLogin::factory()->create([
        'stats_user_id' => $user->id, 'team_id' => $this->team->id, 'project_id' => $this->pid, 'time' => $this->now,
    ]);

    expect(openDayBucketIsCached($filter))->toBeTrue();

    $row = $this->service->getAggregatedData($filter)['2026-06-14'];
    expect($row['pd_dapu'])->toBe(1)
        ->and($row['pd_daapu'])->toBe(1);

    $fresh = freshlyRecomputed($this->service, $filter)['2026-06-14'];
    expect($row['pd_dapu'])->toBe($fresh['pd_dapu'])
        ->and($row['pd_daapu'])->toBe($fresh['pd_daapu']);
});

it('increments revenue for a repeat payment and matches a full recompute', function (): void {
    StatsObserver::$enabled = false;
    $user = StatsUser::factory()->create(array_merge(nullDims(), [
        'team_id' => $this->team->id, 'project_id' => $this->pid, 'device_browser' => 'Chrome', 'time' => $this->now,
    ]));
    StatsPayment::factory()->create([
        'id' => 'pay-1', 'stats_user_id' => $user->id, 'team_id' => $this->team->id, 'project_id' => $this->pid,
        'time' => $this->now, 'counter' => 1, 'gross' => 100, 'net' => 80,
    ]);
    StatsObserver::$enabled = true;

    $chrome = browserId('Chrome');
    $unfiltered = ($this->dayFilter)();
    $chromeFilter = ($this->dayFilter)(['deviceBrowser' => $chrome]);
    $this->service->getAggregatedData($unfiltered);
    $this->service->getAggregatedData($chromeFilter);

    StatsPayment::factory()->create([
        'id' => 'pay-2', 'stats_user_id' => $user->id, 'team_id' => $this->team->id, 'project_id' => $this->pid,
        'time' => $this->now, 'counter' => 2, 'gross' => 50, 'net' => 40,
    ]);

    $unfilteredRow = $this->service->getAggregatedData($unfiltered)['2026-06-14'];
    expect((float) $unfilteredRow['pd_net'])->toBe(120.0)
        ->and((float) $this->service->getAggregatedData($chromeFilter)['2026-06-14']['pd_net'])->toBe(120.0)
        // lifetime net is incremented too (both payments are in today's bucket)
        ->and((float) $unfilteredRow['lt_net'])->toBe(120.0);

    $unfilteredFresh = freshlyRecomputed($this->service, $unfiltered)['2026-06-14'];
    expect((float) $unfilteredFresh['pd_net'])->toBe(120.0)
        ->and((float) $unfilteredFresh['lt_net'])->toBe(120.0)
        ->and((float) freshlyRecomputed($this->service, $chromeFilter)['2026-06-14']['pd_net'])->toBe(120.0);
});

it('invalidates instead of incrementing a repeat payment that reopens the daapu window', function (): void {
    // The user logged in today, but their only prior payment is 25 days ago, outside
    // the 20-day daapu window, so today's pd_daapu starts at 0. A second payment today
    // moves the user back into the window, so pd_daapu must become 1. The increment
    // path cannot bump pd_daapu, so the open bucket has to be invalidated + recomputed.
    StatsObserver::$enabled = false;
    $user = StatsUser::factory()->create(array_merge(nullDims(), [
        'team_id' => $this->team->id, 'project_id' => $this->pid, 'time' => $this->now,
    ]));
    StatsLogin::factory()->create([
        'stats_user_id' => $user->id, 'team_id' => $this->team->id, 'project_id' => $this->pid, 'time' => $this->now,
    ]);
    StatsPayment::factory()->create([
        'id' => 'old-pay', 'stats_user_id' => $user->id, 'team_id' => $this->team->id, 'project_id' => $this->pid,
        'time' => CarbonImmutable::parse($this->now)->subDays(25)->format('Y-m-d H:i:s'),
        'counter' => 1, 'gross' => 100, 'net' => 80,
    ]);
    StatsObserver::$enabled = true;

    $filter = ($this->dayFilter)();
    expect((int) $this->service->getAggregatedData($filter)['2026-06-14']['pd_daapu'])->toBe(0);

    // repeat payment today (counter auto = 2) reopens the 20-day daapu window
    StatsPayment::factory()->create([
        'id' => 'new-pay', 'stats_user_id' => $user->id, 'team_id' => $this->team->id, 'project_id' => $this->pid,
        'time' => $this->now, 'counter' => 2, 'gross' => 50, 'net' => 40,
    ]);

    $row = $this->service->getAggregatedData($filter)['2026-06-14'];
    expect((int) $row['pd_daapu'])->toBe(1)
        ->and((int) $row['pd_daapu'])->toBe((int) freshlyRecomputed($this->service, $filter)['2026-06-14']['pd_daapu'])
        // revenue is still correct after the invalidation + recompute (the 25-day-old
        // payment is outside today's bucket, so only today's net counts)
        ->and((float) $row['pd_net'])->toBe(40.0);
});

it('counts a payment via both the user and the visitor path (cross-entity double path)', function (): void {
    StatsObserver::$enabled = false;
    $user = StatsUser::factory()->create(array_merge(nullDims(), [
        'team_id' => $this->team->id, 'project_id' => $this->pid, 'device_browser' => 'Chrome', 'time' => $this->now,
    ]));
    $visitor = StatsVisitor::factory()->create(array_merge(nullDims(), [
        'team_id' => $this->team->id, 'project_id' => $this->pid, 'device_browser' => 'Safari', 'time' => $this->now,
    ]));
    // first payment (counter auto = 1) so the next one is a repeat (incrementable)
    StatsPayment::factory()->create([
        'id' => 'xe-1', 'stats_user_id' => $user->id, 'team_id' => $this->team->id, 'project_id' => $this->pid,
        'time' => $this->now, 'gross' => 10, 'net' => 10,
    ]);
    StatsObserver::$enabled = true;

    $chrome = browserId('Chrome');
    $safari = browserId('Safari');
    $unfiltered = ($this->dayFilter)();
    $chromeFilter = ($this->dayFilter)(['deviceBrowser' => $chrome]);
    $safariFilter = ($this->dayFilter)(['deviceBrowser' => $safari]);
    $this->service->getAggregatedData($unfiltered);
    $this->service->getAggregatedData($chromeFilter);
    $this->service->getAggregatedData($safariFilter);

    // repeat payment (counter auto = 2) attributed to BOTH a user (Chrome) and a visitor (Safari)
    StatsPayment::factory()->create([
        'id' => 'xe-2', 'stats_user_id' => $user->id, 'stats_visitor_id' => $visitor->id,
        'team_id' => $this->team->id, 'project_id' => $this->pid,
        'time' => $this->now, 'net' => 40, 'gross' => 40,
    ]);

    // the raw query sums the payment via the user RIGHT JOIN AND the visitor RIGHT JOIN,
    // so the unfiltered net counts the repeat payment twice; the increment must match.
    // Chrome (user path) and Safari (visitor path) each see it once.
    expect((float) $this->service->getAggregatedData($chromeFilter)['2026-06-14']['pd_net'])
        ->toBe((float) freshlyRecomputed($this->service, $chromeFilter)['2026-06-14']['pd_net'])
        ->and((float) $this->service->getAggregatedData($safariFilter)['2026-06-14']['pd_net'])
        ->toBe((float) freshlyRecomputed($this->service, $safariFilter)['2026-06-14']['pd_net'])
        ->and((float) $this->service->getAggregatedData($unfiltered)['2026-06-14']['pd_net'])
        ->toBe((float) freshlyRecomputed($this->service, $unfiltered)['2026-06-14']['pd_net']);
});

it('invalidates instead of incrementing for a first payment (counter = 1)', function (): void {
    StatsObserver::$enabled = false;
    $user = StatsUser::factory()->create(array_merge(nullDims(), [
        'team_id' => $this->team->id, 'project_id' => $this->pid, 'time' => $this->now,
    ]));
    StatsObserver::$enabled = true;

    $filter = ($this->dayFilter)();
    $this->service->getAggregatedData($filter);

    StatsPayment::factory()->create([
        'id' => 'pay-first', 'stats_user_id' => $user->id, 'team_id' => $this->team->id, 'project_id' => $this->pid,
        'time' => $this->now, 'counter' => 1, 'gross' => 100, 'net' => 80,
    ]);

    expect(StatsAggregation::where('filter_hash', $filter->makeHash())->where('time', '2026-06-14')->count())->toBe(0);
});

it('invalidates the open bucket for an out-of-order (past) event', function (): void {
    StatsObserver::$enabled = false;
    StatsVisitor::factory()->create(array_merge(nullDims(), [
        'team_id' => $this->team->id, 'project_id' => $this->pid, 'time' => $this->now,
    ]));
    StatsObserver::$enabled = true;

    $filter = ($this->dayFilter)();
    $this->service->getAggregatedData($filter);
    expect(StatsAggregation::where('filter_hash', $filter->makeHash())->where('time', '2026-06-14')->count())->toBe(1);

    // a visitor dated yesterday is out-of-order for the day bucket
    StatsVisitor::factory()->create(array_merge(nullDims(), [
        'team_id' => $this->team->id, 'project_id' => $this->pid, 'time' => '2026-06-13 12:00:00',
    ]));

    expect(StatsAggregation::where('filter_hash', $filter->makeHash())->where('time', '2026-06-14')->count())->toBe(0);
});

it('invalidates custom-property filtered rows instead of incrementing them', function (): void {
    // a custom-property filtered aggregation cannot be safely incremented, so it
    // must be invalidated when any event lands in its bucket
    StatsObserver::$enabled = false;
    StatsVisitor::factory()->create(array_merge(nullDims(), [
        'team_id' => $this->team->id, 'project_id' => $this->pid, 'time' => $this->now,
    ]));
    StatsObserver::$enabled = true;

    // fabricate a property-filtered cached row for the open bucket
    StatsAggregation::create([
        'team_id' => $this->team->id,
        'project_id' => $this->pid,
        'filter_hash' => 'prop-filter-hash',
        'filter' => ['property_value' => 42],
        'type' => StatsRangeType::DAY,
        'time' => '2026-06-14',
        'data' => ['date_clear' => '2026-06-14', 'pd_visitor' => 99],
        'selected_at' => now(),
    ]);

    StatsVisitor::factory()->create(array_merge(nullDims(), [
        'team_id' => $this->team->id, 'project_id' => $this->pid, 'time' => $this->now,
    ]));

    expect(StatsAggregation::where('filter_hash', 'prop-filter-hash')->count())->toBe(0);
});

it('keeps a registration-cohort row in line with a full recompute when new stats arrive', function (): void {
    $cohortFilter = ($this->dayFilter)([
        'cohortStart' => CarbonImmutable::parse('2026-02-01 00:00:00'),
        'cohortEnd' => CarbonImmutable::parse('2026-03-01 00:00:00'),
    ]);

    expect($this->service->getAggregatedData($cohortFilter)['2026-06-14'])
        ->pd_visitor->toBe(0)
        ->pd_reg->toBe(0);

    // neither belongs to the February cohort: visitors never do, and the user registers today
    StatsVisitor::factory()->create(array_merge(nullDims(), [
        'team_id' => $this->team->id, 'project_id' => $this->pid, 'time' => $this->now,
    ]));
    StatsUser::factory()->create(array_merge(nullDims(), [
        'team_id' => $this->team->id, 'project_id' => $this->pid, 'time' => $this->now,
    ]));

    expect($this->service->getAggregatedData($cohortFilter)['2026-06-14'])
        ->pd_visitor->toBe(0)
        ->pd_reg->toBe(0);
});

it('does nothing when the aggregation cache is disabled entirely', function (): void {
    config(['app.stats_aggregation_enabled' => false]);

    StatsAggregation::create([
        'team_id' => $this->team->id,
        'project_id' => $this->pid,
        'filter_hash' => 'untouched-hash',
        'filter' => [],
        'type' => StatsRangeType::DAY,
        'time' => '2026-06-14',
        'data' => ['date_clear' => '2026-06-14', 'pd_visitor' => 7],
        'selected_at' => now(),
    ]);

    StatsVisitor::factory()->create(array_merge(nullDims(), [
        'team_id' => $this->team->id, 'project_id' => $this->pid, 'time' => $this->now,
    ]));

    // the row is neither incremented nor invalidated
    expect((int) StatsAggregation::where('filter_hash', 'untouched-hash')->value('data')['pd_visitor'])->toBe(7);
});

it('increments the team-level (project_id null) aggregation', function (): void {
    StatsObserver::$enabled = false;
    StatsVisitor::factory()->count(3)->create(array_merge(nullDims(), [
        'team_id' => $this->team->id, 'project_id' => $this->pid, 'time' => $this->now,
    ]));
    StatsObserver::$enabled = true;

    // projectId 0 produces the team-wide aggregation (stored with project_id null)
    $teamFilter = new StatsFilterDto(
        projectId: 0, rangeAmount: 1, rangeType: StatsRangeType::DAY,
        rangeStart: CarbonImmutable::parse($this->now), comparison: '0',
    );
    expect($this->service->getAggregatedData($teamFilter)['2026-06-14']['pd_visitor'])->toBe(3);
    expect(StatsAggregation::where('filter_hash', $teamFilter->makeHash())->whereNull('project_id')->count())->toBe(1);

    StatsVisitor::factory()->create(array_merge(nullDims(), [
        'team_id' => $this->team->id, 'project_id' => $this->pid, 'time' => $this->now,
    ]));

    expect($this->service->getAggregatedData($teamFilter)['2026-06-14']['pd_visitor'])->toBe(4)
        ->and(freshlyRecomputed($this->service, $teamFilter)['2026-06-14']['pd_visitor'])->toBe(4);
});

it('invalidates legacy rows without a stored filter', function (): void {
    // a row with a null filter cannot be matched, so it is invalidated on any event
    StatsAggregation::create([
        'team_id' => $this->team->id,
        'project_id' => $this->pid,
        'filter_hash' => 'legacy-hash',
        'filter' => null,
        'type' => StatsRangeType::DAY,
        'time' => '2026-06-14',
        'data' => ['date_clear' => '2026-06-14', 'pd_visitor' => 99],
        'selected_at' => now(),
    ]);

    StatsVisitor::factory()->create(array_merge(nullDims(), [
        'team_id' => $this->team->id, 'project_id' => $this->pid, 'time' => $this->now,
    ]));

    expect(StatsAggregation::where('filter_hash', 'legacy-hash')->count())->toBe(0);
});

it('increments the open weekly bucket', function (): void {
    StatsObserver::$enabled = false;
    StatsVisitor::factory()->count(5)->create(array_merge(nullDims(), [
        'team_id' => $this->team->id, 'project_id' => $this->pid, 'time' => $this->now,
    ]));
    StatsObserver::$enabled = true;

    $weekFilter = new StatsFilterDto(
        projectId: $this->pid, rangeAmount: 1, rangeType: StatsRangeType::WEEK,
        rangeStart: CarbonImmutable::parse($this->now), comparison: '0',
    );
    expect($this->service->getAggregatedData($weekFilter)->first()['pd_visitor'])->toBe(5);

    StatsVisitor::factory()->create(array_merge(nullDims(), [
        'team_id' => $this->team->id, 'project_id' => $this->pid, 'time' => $this->now,
    ]));

    expect($this->service->getAggregatedData($weekFilter)->first()['pd_visitor'])->toBe(6)
        ->and(freshlyRecomputed($this->service, $weekFilter)->first()['pd_visitor'])->toBe(6);
});
