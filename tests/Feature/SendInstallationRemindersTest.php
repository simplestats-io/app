<?php

use App\Models\Project;
use App\Models\User;
use App\Notifications\InstallationReminderNotification;
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
