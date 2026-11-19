<?php

use App\Models\Team;
use App\Models\User;
use App\Notifications\UserDeletedNotification;
use App\Notifications\UserInactiveNotification;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\Notification;

beforeEach(fn () => $this->skipUnlessSaas());

it('marks inactive users for deletion after the 10 day warning period', function (): void {
    Notification::fake();

    $user = User::factory()->withPersonalTeam()->create([
        'inactive_since' => now()->subDays(11),
    ]);
    $personalTeam = $user->personalTeam();

    $this->artisan('app:check-inactive-users');

    // Soft mark only: user and their data survive the grace window.
    expect($user->fresh())->not->toBeNull();
    expect($user->fresh()->deleted_at)->not->toBeNull();
    expect($personalTeam->fresh())->not->toBeNull();
    Notification::assertSentTo($user, UserDeletedNotification::class);
});

it('does not re-notify users already marked for deletion', function (): void {
    Notification::fake();

    $user = User::factory()->withPersonalTeam()->create([
        'inactive_since' => now()->subDays(11),
        'deleted_at' => now()->subDay(),
    ]);

    $this->artisan('app:check-inactive-users');

    Notification::assertNotSentTo($user, UserDeletedNotification::class);
    Notification::assertNotSentTo($user, UserInactiveNotification::class);
});

it('does not mark users with active subscriptions', function (): void {
    Notification::fake();

    $user = User::factory()->withPersonalTeam()->create([
        'inactive_since' => now()->subDays(11),
    ]);
    $user->subscriptions()->create([
        'type' => 'default',
        'paddle_id' => 'sub_test',
        'status' => 'active',
    ]);

    $this->artisan('app:check-inactive-users');

    expect($user->fresh()->deleted_at)->toBeNull();
    Notification::assertNotSentTo($user, UserDeletedNotification::class);
});

it('does not mark users whose subscription is past due', function (): void {
    Notification::fake();

    $user = User::factory()->withPersonalTeam()->create([
        'inactive_since' => now()->subDays(11),
    ]);
    $user->subscriptions()->create([
        'type' => 'default',
        'paddle_id' => 'sub_past_due',
        'status' => 'past_due',
    ]);

    $this->artisan('app:check-inactive-users');

    expect($user->fresh()->deleted_at)->toBeNull();
    Notification::assertNotSentTo($user, UserDeletedNotification::class);
});

it('sends 10 day warning to users inactive for 50+ days', function (): void {
    Notification::fake();

    $user = User::factory()->withPersonalTeam()->create([
        'last_login_at' => now()->subDays(51),
        'inactive_since' => null,
    ]);

    $this->artisan('app:check-inactive-users');

    expect($user->fresh()->inactive_since)->not->toBeNull();
    Notification::assertSentTo($user, UserInactiveNotification::class);
});

it('sends 1 day final warning to users warned 9+ days ago', function (): void {
    Notification::fake();

    $user = User::factory()->withPersonalTeam()->create([
        'inactive_since' => now()->subDays(9)->subHour(),
        'last_login_at' => now()->subDays(60),
    ]);

    $this->artisan('app:check-inactive-users');

    // Should receive final warning but NOT be marked (only after 10 days)
    expect($user->fresh()->deleted_at)->toBeNull();
    Notification::assertSentTo($user, UserInactiveNotification::class);
});

it('clears the warning when a warned user logs back in', function (): void {
    $user = User::factory()->withPersonalTeam()->create([
        'inactive_since' => now()->subDays(5),
        'last_login_at' => now()->subDays(55),
    ]);

    event(new Login('web', $user, false));

    expect($user->fresh()->inactive_since)->toBeNull();
    expect($user->fresh()->last_login_at)->not->toBeNull();
});

it('does not delete a user who logged back in after the warning', function (): void {
    Notification::fake();

    $user = User::factory()->withPersonalTeam()->create([
        'inactive_since' => now()->subDays(11),
        'last_login_at' => now()->subDays(61),
    ]);

    // User logs back in before the command runs, which clears inactive_since.
    event(new Login('web', $user, false));

    $this->artisan('app:check-inactive-users');

    expect($user->fresh())->not->toBeNull();
    expect($user->fresh()->inactive_since)->toBeNull();
    Notification::assertNotSentTo($user, UserDeletedNotification::class);
});

it('does not send a final warning to a user who logged back in after the reminder', function (): void {
    Notification::fake();

    // User already received the first warning 9+ days ago (final-warning window).
    $user = User::factory()->withPersonalTeam()->create([
        'inactive_since' => now()->subDays(9)->subHour(),
        'last_login_at' => now()->subDays(60),
    ]);

    event(new Login('web', $user, false));

    $this->artisan('app:check-inactive-users');

    expect($user->fresh())->not->toBeNull();
    expect($user->fresh()->inactive_since)->toBeNull();
    Notification::assertNotSentTo($user, UserInactiveNotification::class);
    Notification::assertNotSentTo($user, UserDeletedNotification::class);
});

it('warns the returning user again only after a fresh inactivity period', function (): void {
    Notification::fake();

    $user = User::factory()->withPersonalTeam()->create([
        'inactive_since' => now()->subDays(11),
        'last_login_at' => now()->subDays(61),
    ]);

    event(new Login('web', $user, false));

    $this->artisan('app:check-inactive-users');
    expect($user->fresh())->not->toBeNull();
    Notification::assertNothingSentTo($user);

    // A fresh inactivity period must pass before the user is warned again.
    $user->update(['last_login_at' => now()->subDays(51)]);

    $this->artisan('app:check-inactive-users');

    expect($user->fresh()->inactive_since)->not->toBeNull();
    Notification::assertSentTo($user, UserInactiveNotification::class);
});

it('middleware switches user to personal team when current team owner is missing', function (): void {
    $owner = User::factory()->withPersonalTeam()->create();
    $sharedTeam = Team::factory()->create([
        'user_id' => $owner->id,
        'personal_team' => false,
    ]);

    $member = User::factory()->withPersonalTeam()->create([
        'current_team_id' => $sharedTeam->id,
    ]);
    $sharedTeam->users()->attach($member, ['role' => 'editor']);
    $personalTeam = $member->personalTeam();

    // Simulate owner deletion without proper cleanup (the bug scenario)
    $owner->forceDelete();

    $this->actingAs($member);
    $this->get('/dashboard');

    expect($member->fresh()->current_team_id)->toBe($personalTeam->id);
});

it('does not mark an owner whose team is still used by another member', function (): void {
    Notification::fake();

    // Deleting the owner purges the whole team, and only the owner is warned.
    $owner = User::factory()->withPersonalTeam()->create([
        'inactive_since' => now()->subDays(11),
        'last_login_at' => now()->subDays(61),
    ]);
    $sharedTeam = Team::factory()->create([
        'user_id' => $owner->id,
        'personal_team' => false,
    ]);

    $member = User::factory()->withPersonalTeam()->create(['last_login_at' => now()->subDay()]);
    $sharedTeam->users()->attach($member, ['role' => 'editor']);

    $this->artisan('app:check-inactive-users');

    expect($owner->fresh()->deleted_at)->toBeNull();
    expect($sharedTeam->fresh())->not->toBeNull();
    Notification::assertNotSentTo($owner, UserDeletedNotification::class);
});

it('marks an owner whose team members went inactive as well', function (): void {
    Notification::fake();

    $owner = User::factory()->withPersonalTeam()->create([
        'inactive_since' => now()->subDays(11),
        'last_login_at' => now()->subDays(61),
    ]);
    $sharedTeam = Team::factory()->create([
        'user_id' => $owner->id,
        'personal_team' => false,
    ]);

    $member = User::factory()->withPersonalTeam()->create(['last_login_at' => now()->subDays(61)]);
    $sharedTeam->users()->attach($member, ['role' => 'editor']);

    $this->artisan('app:check-inactive-users');

    expect($owner->fresh()->deleted_at)->not->toBeNull();
    Notification::assertSentTo($owner, UserDeletedNotification::class);
});

it('does not warn an owner whose team is still used by another member', function (): void {
    Notification::fake();

    $owner = User::factory()->withPersonalTeam()->create([
        'last_login_at' => now()->subDays(51),
        'inactive_since' => null,
    ]);
    $sharedTeam = Team::factory()->create([
        'user_id' => $owner->id,
        'personal_team' => false,
    ]);

    $member = User::factory()->withPersonalTeam()->create(['last_login_at' => now()->subDay()]);
    $sharedTeam->users()->attach($member, ['role' => 'editor']);

    $this->artisan('app:check-inactive-users');

    expect($owner->fresh()->inactive_since)->toBeNull();
    Notification::assertNotSentTo($owner, UserInactiveNotification::class);
});

it('still marks a forgotten account whose app keeps sending data', function (): void {
    Notification::fake();

    // Traffic alone is not engagement: a free account that ignores both warning
    // mails gets cleaned up even while its client package keeps tracking.
    $user = User::factory()->withPersonalTeam()->create([
        'inactive_since' => now()->subDays(11),
        'last_login_at' => now()->subDays(61),
    ]);
    $user->personalTeam()->update(['events_count_monthly' => 5000]);

    $this->artisan('app:check-inactive-users');

    expect($user->fresh()->deleted_at)->not->toBeNull();
    Notification::assertSentTo($user, UserDeletedNotification::class);
});
