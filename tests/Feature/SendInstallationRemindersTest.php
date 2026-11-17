<?php

use App\Models\Project;
use App\Models\User;
use App\Notifications\InstallationReminderNotification;
use App\Notifications\SetupReminderNotification;
use Illuminate\Support\Facades\Notification;

beforeEach(function (): void {
    Notification::fake();

    $this->owner = User::factory()->withPersonalTeam()->create();
    $this->team = $this->owner->currentTeam;
});

function createReminderProjectAgo(object $test, int $hours): Project
{
    return Project::factory()->create([
        'team_id' => $test->team->id,
        'created_at' => now()->subHours($hours),
    ]);
}

it('reminds the owner once when the project never sent data after three days', function (): void {
    $project = createReminderProjectAgo($this, 24 * 4);

    $this->artisan('app:send-installation-reminders')->assertSuccessful();
    $this->artisan('app:send-installation-reminders')->assertSuccessful();

    Notification::assertSentToTimes($this->owner, InstallationReminderNotification::class, 1);
    Notification::assertSentTo(
        $this->owner,
        InstallationReminderNotification::class,
        fn (InstallationReminderNotification $notification): bool => $notification->project->is($project),
    );
    expect($this->team->fresh()->installation_reminder_sent_at)->not->toBeNull();
});

it('does not remind teams that already received data', function (): void {
    createReminderProjectAgo($this, 24 * 4);
    $this->team->update(['events_count_overall' => 5]);

    $this->artisan('app:send-installation-reminders');

    Notification::assertNothingSent();
});

it('waits three days before reminding and skips projects older than a week', function (int $hours): void {
    createReminderProjectAgo($this, $hours);

    $this->artisan('app:send-installation-reminders');

    Notification::assertNothingSent();
})->with([
    'two days old' => 24 * 2,
    'older than a week' => 24 * 8,
]);

it('does not mail a soft-deleted owner', function (): void {
    createReminderProjectAgo($this, 24 * 4);
    $this->owner->delete();

    $this->artisan('app:send-installation-reminders');

    Notification::assertNothingSent();
});

it('links to the dashboard and names the project', function (): void {
    $project = createReminderProjectAgo($this, 24 * 4);

    $mail = (new InstallationReminderNotification($this->owner, $project))->toMail($this->owner);

    expect($mail->actionUrl)->toBe(route('dashboard'))
        ->and(implode(' ', $mail->introLines))->toContain($project->name);
});

function ageReminderTeam(object $test, int $hours): void
{
    $test->team->forceFill(['created_at' => now()->subHours($hours)])->save();
}

it('reminds the owner once to finish the setup when they never got past the team or project step', function (bool $hasUnnamedProject): void {
    ageReminderTeam($this, 24 * 4);

    if ($hasUnnamedProject) {
        Project::factory()->create(['team_id' => $this->team->id, 'name' => '']);
    }

    $this->artisan('app:send-installation-reminders')->assertSuccessful();
    $this->artisan('app:send-installation-reminders')->assertSuccessful();

    Notification::assertSentToTimes($this->owner, SetupReminderNotification::class, 1);
    Notification::assertNotSentTo($this->owner, InstallationReminderNotification::class);
})->with([
    'stopped at the team step' => false,
    'stopped at the project step' => true,
]);

it('does not send a setup reminder before three days or after a week', function (int $hours): void {
    ageReminderTeam($this, $hours);

    $this->artisan('app:send-installation-reminders');

    Notification::assertNothingSent();
})->with([
    'two days old' => 24 * 2,
    'older than a week' => 24 * 8,
]);

it('does not send a setup reminder to users who signed up to join another team', function (): void {
    ageReminderTeam($this, 24 * 4);
    $otherTeam = User::factory()->withPersonalTeam()->create()->currentTeam;
    $otherTeam->users()->attach($this->owner, ['role' => 'editor']);

    $this->artisan('app:send-installation-reminders');

    Notification::assertNothingSent();
});

it('does not send a setup reminder to users with a pending invitation to another team', function (): void {
    ageReminderTeam($this, 24 * 4);
    $otherTeam = User::factory()->withPersonalTeam()->create()->currentTeam;
    $otherTeam->teamInvitations()->create(['email' => $this->owner->email, 'role' => 'editor']);

    $this->artisan('app:send-installation-reminders');

    Notification::assertNothingSent();
});

it('does not send a setup reminder for an additional team that is not the personal one', function (): void {
    ageReminderTeam($this, 24 * 4);
    $this->team->update(['personal_team' => false]);

    $this->artisan('app:send-installation-reminders');

    Notification::assertNothingSent();
});

it('links the setup reminder to the dashboard', function (): void {
    $mail = (new SetupReminderNotification($this->owner))->toMail($this->owner);

    expect($mail->actionUrl)->toBe(route('dashboard'));
});
