<?php

use App\Enums\SubscriptionInterval;
use App\Helpers\AppHelper;
use App\Models\Project;
use App\Models\StatsAggregation;
use App\Models\StatsPayment;
use App\Models\StatsUser;
use App\Models\StatsVisitor;
use App\Models\User;
use Database\Seeders\TestUserSeeder;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    $this->seed(TestUserSeeder::class);
    $this->user = User::where('email', config('app.test.user')['email'])->firstOrFail();
    $this->project = Project::first();
    $this->token = $this->project->createToken('test-token')->plainTextToken;

    // TestUserSeeder leaves the test user on the web guard; forget it so the
    // Bearer token (the project) wins over Sanctum's stateful session check.
    auth()->forgetGuards();
});

function validPaymentPayload(array $overrides = []): array
{
    return array_merge([
        'id' => fake()->uuid(),
        'time' => now()->format('Y-m-d H:i:s P'),
        'gross' => 9999,
        'net' => 8399,
        'currency' => 'EUR',
    ], $overrides);
}

// Attributes the payment to a stats user so the subscription assertions exercise
// the persistence path without depending on the person-less payment alert flow.
function subscriptionPaymentPayload(array $overrides = []): array
{
    return validPaymentPayload(array_merge([
        'stats_user_id' => (string) fake()->unique()->randomNumber(5),
        'stats_user_time' => now()->subDay()->format('Y-m-d H:i:s P'),
    ], $overrides));
}

function setPaymentProjectContext(?Authenticatable $project): void
{
    auth()->setUser($project);
    AppHelper::setTeamContextFromAuth($project);
    Context::add('project_id', $project->id);
    Context::add('request_url', '/api/v1/stats-payment');
}

test('store payment with auth and required fields returns 200', function (): void {
    $payload = validPaymentPayload();

    $response = $this->postJson(
        '/api/v1/stats-payment',
        $payload,
        apiHeaders($this->token),
    );

    $response->assertOk()
        ->assertJson([
            'message' => 'Stats Payment Request accepted.',
            'id' => $payload['id'],
        ]);
});

test('store payment without auth returns 401', function (): void {
    auth()->forgetGuards();

    $response = $this->postJson(
        '/api/v1/stats-payment',
        validPaymentPayload(),
        [
            'Authorization' => 'Bearer invalid-token',
            'Accept' => 'application/json',
        ],
    );

    $response->assertUnauthorized();
});

test('validation fails when id is missing', function (): void {
    $response = $this->postJson(
        '/api/v1/stats-payment',
        validPaymentPayload(['id' => null]),
        apiHeaders($this->token),
    );

    $response->assertUnprocessable()
        ->assertJsonValidationErrors('id');
});

test('validation fails when gross is missing', function (): void {
    $payload = validPaymentPayload();
    unset($payload['gross']);

    $response = $this->postJson(
        '/api/v1/stats-payment',
        $payload,
        apiHeaders($this->token),
    );

    $response->assertUnprocessable()
        ->assertJsonValidationErrors('gross');
});

test('validation fails when net is missing', function (): void {
    $payload = validPaymentPayload();
    unset($payload['net']);

    $response = $this->postJson(
        '/api/v1/stats-payment',
        $payload,
        apiHeaders($this->token),
    );

    $response->assertUnprocessable()
        ->assertJsonValidationErrors('net');
});

test('validation fails when currency is missing', function (): void {
    $payload = validPaymentPayload();
    unset($payload['currency']);

    $response = $this->postJson(
        '/api/v1/stats-payment',
        $payload,
        apiHeaders($this->token),
    );

    $response->assertUnprocessable()
        ->assertJsonValidationErrors('currency');
});

test('validation fails when currency is invalid', function (): void {
    $response = $this->postJson(
        '/api/v1/stats-payment',
        validPaymentPayload(['currency' => 'INVALID']),
        apiHeaders($this->token),
    );

    $response->assertUnprocessable()
        ->assertJsonValidationErrors('currency');
});

test('validation fails when time is missing', function (): void {
    $payload = validPaymentPayload();
    unset($payload['time']);

    $response = $this->postJson(
        '/api/v1/stats-payment',
        $payload,
        apiHeaders($this->token),
    );

    $response->assertUnprocessable()
        ->assertJsonValidationErrors('time');
});

test('payment is created with correct amounts', function (): void {
    $payload = validPaymentPayload([
        'gross' => 5000,
        'net' => 4200,
    ]);

    $this->postJson('/api/v1/stats-payment', $payload, apiHeaders($this->token))->assertOk();

    setPaymentProjectContext($this->project);

    $payment = StatsPayment::where('id', $payload['id'])->first();
    expect($payment)->not->toBeNull()
        ->and($payment->gross)->toBe(5000.0)
        ->and($payment->net)->toBe(4200.0);
});

test('new zero amount payment is not stored', function (): void {
    $payload = validPaymentPayload([
        'gross' => 0,
        'net' => 0,
    ]);

    $this->postJson('/api/v1/stats-payment', $payload, apiHeaders($this->token))->assertOk();

    setPaymentProjectContext($this->project);

    expect(StatsPayment::where('id', $payload['id'])->exists())->toBeFalse();
});

test('existing payment updated to zero amount is deleted as a full refund', function (): void {
    $payload = validPaymentPayload();

    $this->postJson('/api/v1/stats-payment', $payload, apiHeaders($this->token))->assertOk();

    setPaymentProjectContext($this->project);
    expect(StatsPayment::whereKey($payload['id'])->exists())->toBeTrue();

    $this->postJson('/api/v1/stats-payment', [...$payload, 'gross' => 0, 'net' => 0], apiHeaders($this->token))->assertOk();

    setPaymentProjectContext($this->project);
    expect(StatsPayment::whereKey($payload['id'])->exists())->toBeFalse();
});

test('later payments of the user move up when a refunded payment is deleted', function (): void {
    $first = subscriptionPaymentPayload(['time' => now()->subDays(2)->format('Y-m-d H:i:s P')]);
    $second = validPaymentPayload([
        'stats_user_id' => $first['stats_user_id'],
        'stats_user_time' => $first['stats_user_time'],
    ]);

    $this->postJson('/api/v1/stats-payment', $first, apiHeaders($this->token))->assertOk();
    $this->postJson('/api/v1/stats-payment', $second, apiHeaders($this->token))->assertOk();
    $this->postJson('/api/v1/stats-payment', [...$first, 'gross' => 0, 'net' => 0], apiHeaders($this->token))->assertOk();

    setPaymentProjectContext($this->project);

    expect(StatsPayment::whereKey($first['id'])->exists())->toBeFalse()
        ->and(StatsPayment::whereKey($second['id'])->value('counter'))->toBe(1);
});

test('refund deletion invalidates the cache of a shifted payment that lies before it', function (): void {
    $later = subscriptionPaymentPayload(['time' => '2026-05-10 10:00:00 +00:00']);
    $earlier = validPaymentPayload([
        'stats_user_id' => $later['stats_user_id'],
        'stats_user_time' => $later['stats_user_time'],
        'time' => '2026-03-10 10:00:00 +00:00',
    ]);

    $this->postJson('/api/v1/stats-payment', $later, apiHeaders($this->token))->assertOk();
    $this->postJson('/api/v1/stats-payment', $earlier, apiHeaders($this->token))->assertOk();

    StatsAggregation::query()->withoutGlobalScopes()->delete();
    DB::table('stats_aggregations')->insert([
        'team_id' => $this->project->team_id,
        'project_id' => $this->project->id,
        'filter_hash' => md5('march'),
        'filter' => '[]',
        'type' => 'months',
        'time' => '2026-03-01',
        'kind' => 'period',
        'data' => json_encode(['pd_pu' => 0]),
    ]);

    $this->postJson('/api/v1/stats-payment', [...$later, 'gross' => 0, 'net' => 0], apiHeaders($this->token))->assertOk();

    expect(DB::table('stats_aggregations')->where('project_id', $this->project->id)->exists())->toBeFalse();
});

test('payment alert does not crash for a payment without an attributed person', function (): void {
    // The project's default payment alert fires on creation; a payment with
    // neither a stats user nor a visitor must still render the alert (amount
    // and time only) instead of dereferencing a null person.
    expect($this->project->userAlertNotifications()->where('settings->tracking->payments', true)->exists())->toBeTrue();

    $payload = validPaymentPayload();

    $this->postJson('/api/v1/stats-payment', $payload, apiHeaders($this->token))->assertOk();

    setPaymentProjectContext($this->project);

    $payment = StatsPayment::where('id', $payload['id'])->first();
    expect($payment)->not->toBeNull()
        ->and($payment->stats_user_id)->toBeNull()
        ->and($payment->stats_visitor_id)->toBeNull();
});

test('subscription_interval is stored when provided', function (): void {
    $payload = subscriptionPaymentPayload(['subscription_interval' => 'month']);

    $this->postJson('/api/v1/stats-payment', $payload, apiHeaders($this->token))->assertOk();

    setPaymentProjectContext($this->project);

    $payment = StatsPayment::where('id', $payload['id'])->first();
    expect($payment)->not->toBeNull()
        ->and($payment->subscription_interval)->toBe(SubscriptionInterval::MONTH);
});

test('subscription_plan is stored when provided', function (): void {
    $payload = subscriptionPaymentPayload(['subscription_interval' => 'month', 'subscription_plan' => 'Business']);

    $this->postJson('/api/v1/stats-payment', $payload, apiHeaders($this->token))->assertOk();

    setPaymentProjectContext($this->project);

    $payment = StatsPayment::where('id', $payload['id'])->first();
    expect($payment)->not->toBeNull()
        ->and($payment->subscription_plan)->toBe('Business');
});

test('subscription_interval defaults to null for one-time payments', function (): void {
    $payload = subscriptionPaymentPayload();

    $this->postJson('/api/v1/stats-payment', $payload, apiHeaders($this->token))->assertOk();

    setPaymentProjectContext($this->project);

    $payment = StatsPayment::where('id', $payload['id'])->first();
    expect($payment)->not->toBeNull()
        ->and($payment->subscription_interval)->toBeNull()
        ->and($payment->subscription_plan)->toBeNull();
});

test('validation fails when subscription_interval is invalid', function (): void {
    $response = $this->postJson(
        '/api/v1/stats-payment',
        validPaymentPayload(['subscription_interval' => 'weekly']),
        apiHeaders($this->token),
    );

    $response->assertUnprocessable()
        ->assertJsonValidationErrors('subscription_interval');
});

test('payment with stats_user_id is stored correctly and StatsUser is auto-created', function (): void {
    $statsUserId = (string) fake()->unique()->randomNumber(5);

    $payload = validPaymentPayload([
        'stats_user_id' => $statsUserId,
        'stats_user_time' => now()->subDay()->format('Y-m-d H:i:s P'),
    ]);

    $this->postJson('/api/v1/stats-payment', $payload, apiHeaders($this->token))->assertOk();

    setPaymentProjectContext($this->project);

    $payment = StatsPayment::where('id', $payload['id'])->first();
    expect($payment)->not->toBeNull()
        ->and($payment->stats_user_id)->toBe($statsUserId);

    $statsUser = StatsUser::where('id', $statsUserId)->first();
    expect($statsUser)->not->toBeNull();
});

test('payment with visitor_hash resolves to stats_visitor_id', function (): void {
    setPaymentProjectContext($this->project);

    $visitor = StatsVisitor::factory()->create(['project_id' => $this->project->id]);

    $payload = validPaymentPayload([
        'visitor_hash' => $visitor->visitor_hash,
    ]);

    $this->postJson('/api/v1/stats-payment', $payload, apiHeaders($this->token))->assertOk();

    $payment = StatsPayment::where('id', $payload['id'])->first();
    expect($payment)->not->toBeNull()
        ->and($payment->stats_visitor_id)->toBe($visitor->id);
});

test('duplicate id performs updateOrCreate update', function (): void {
    $paymentId = fake()->uuid();

    $payload1 = validPaymentPayload(['id' => $paymentId, 'gross' => 1000, 'net' => 800]);
    $this->postJson('/api/v1/stats-payment', $payload1, apiHeaders($this->token))->assertOk();

    $payload2 = validPaymentPayload(['id' => $paymentId, 'gross' => 2000, 'net' => 1600]);
    $this->postJson('/api/v1/stats-payment', $payload2, apiHeaders($this->token))->assertOk();

    setPaymentProjectContext($this->project);

    $payments = StatsPayment::where('id', $paymentId)->get();
    expect($payments)->toHaveCount(1);
});

test('counter increments per user', function (): void {
    setPaymentProjectContext($this->project);

    $statsUser = StatsUser::factory()->create(['project_id' => $this->project->id]);

    $payload1 = validPaymentPayload([
        'id' => 'pay-counter-1',
        'stats_user_id' => $statsUser->id,
        'stats_user_time' => now()->subDay()->format('Y-m-d H:i:s P'),
    ]);

    $this->postJson('/api/v1/stats-payment', $payload1, apiHeaders($this->token))->assertOk();

    $payment1 = StatsPayment::where('id', 'pay-counter-1')->first();
    expect($payment1->counter)->toBe(1);

    $payload2 = validPaymentPayload([
        'id' => 'pay-counter-2',
        'stats_user_id' => $statsUser->id,
        'stats_user_time' => now()->subDay()->format('Y-m-d H:i:s P'),
    ]);

    $this->postJson('/api/v1/stats-payment', $payload2, apiHeaders($this->token))->assertOk();

    $payment2 = StatsPayment::where('id', 'pay-counter-2')->first();
    expect($payment2->counter)->toBe(2);
});

test('events_count_monthly is incremented on team via StatsObserver', function (): void {
    $team = $this->project->team;
    $initialCount = $team->events_count_monthly;

    $payload = validPaymentPayload();
    $this->postJson('/api/v1/stats-payment', $payload, apiHeaders($this->token))->assertOk();

    $team->refresh();
    expect($team->events_count_monthly)->toBeGreaterThan($initialCount);
});
