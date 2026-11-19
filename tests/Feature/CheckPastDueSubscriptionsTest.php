<?php

use App\Mail\SubscriptionPastDue;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

beforeEach(fn () => $this->skipUnlessSaas());

function subscribeWithStatus(User $user, string $status): void
{
    $plan = config('spark.billables.user.plans')[1];

    $subscription = $user->subscriptions()->create([
        'type' => 'default',
        'paddle_id' => 'sub_'.$status.'_'.$user->id,
        'status' => $status,
    ]);

    $subscription->items()->create([
        'product_id' => 'pro_test',
        'price_id' => $plan['monthly_id'],
        'status' => $status,
        'quantity' => 1,
    ]);
}

it('keeps the paid plan while the subscription is past due', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    subscribeWithStatus($user, 'past_due');

    expect($user->fresh()->sparkPlan()->name)->toBe(config('spark.billables.user.plans')[1]['name']);
});

it('reminds users with a past due subscription', function (): void {
    Mail::fake();

    $pastDue = User::factory()->withPersonalTeam()->create();
    subscribeWithStatus($pastDue, 'past_due');

    $active = User::factory()->withPersonalTeam()->create();
    subscribeWithStatus($active, 'active');

    $this->artisan('app:check-past-due-subscriptions')->assertSuccessful();

    Mail::assertQueued(SubscriptionPastDue::class, fn (SubscriptionPastDue $mail): bool => $mail->hasTo($pastDue->email));
    Mail::assertNotQueued(SubscriptionPastDue::class, fn (SubscriptionPastDue $mail): bool => $mail->hasTo($active->email));
});

it('renders the reminder with a link to the billing portal', function (): void {
    $user = User::factory()->create();

    (new SubscriptionPastDue($user))->assertSeeInHtml(route('spark.portal'));
});
