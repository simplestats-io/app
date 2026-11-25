<?php

use App\Models\Project;
use App\Models\StatsVisitor;
use App\Models\Team;
use App\Models\User;
use App\Notifications\Lifecycle\DataStoppedNotification;
use App\Notifications\Lifecycle\InstallationReminderNotification;
use App\Notifications\Lifecycle\NoDataFeedbackNotification;
use App\Notifications\Lifecycle\SetupReminderNotification;
use App\Services\SubscriptionService;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;

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

function ageReminderTeam(object $test, int $hours): void
{
    $test->team->forceFill(['created_at' => now()->subHours($hours)])->save();
}

function createVisitorDaysAgo(Team $team, Project $project, int $days): void
{
    // The time setter converts into the team timezone of the authenticated user.
    $team->update(['timezone' => 'Europe/Berlin']);
    auth()->setUser($team->owner);
    StatsVisitor::factory()->create(['team_id' => $team->id, 'project_id' => $project->id, 'time' => now()->subDays($days)]);
    auth()->forgetGuards();
}

it('reminds the owner once when the project never sent data after three days', function (): void {
    $project = createReminderProjectAgo($this, 24 * 4);

    $this->artisan('app:send-lifecycle-mails')->assertSuccessful();
    $this->artisan('app:send-lifecycle-mails')->assertSuccessful();

    Notification::assertSentToTimes($this->owner, InstallationReminderNotification::class, 1);
    Notification::assertSentTo(
        $this->owner,
        InstallationReminderNotification::class,
        fn (InstallationReminderNotification $notification): bool => $notification->project()->is($project),
    );
    expect($this->team->lifecycleMails()->pluck('mail')->all())->toBe(['installation_reminder']);
});

it('does not remind teams that already received data', function (): void {
    createReminderProjectAgo($this, 24 * 4);
    $this->team->update(['events_count_overall' => 5]);

    $this->artisan('app:send-lifecycle-mails');

    Notification::assertNothingSent();
});

it('waits three days before reminding and skips projects older than a week', function (int $hours): void {
    createReminderProjectAgo($this, $hours);

    $this->artisan('app:send-lifecycle-mails');

    Notification::assertNotSentTo($this->owner, InstallationReminderNotification::class);
})->with([
    'two days old' => 24 * 2,
    'older than a week' => 24 * 8,
]);

it('does not mail soft-deleted, unverified or opted-out owners', function (string $state): void {
    createReminderProjectAgo($this, 24 * 4);

    match ($state) {
        'soft-deleted' => $this->owner->delete(),
        'unverified' => $this->owner->forceFill(['email_verified_at' => null])->save(),
        'opted out' => $this->owner->touch('lifecycle_mails_opted_out_at'),
    };

    $this->artisan('app:send-lifecycle-mails');

    Notification::assertNothingSent();
    expect($this->team->lifecycleMails()->exists())->toBeFalse();
})->with(['soft-deleted', 'unverified', 'opted out']);

it('links to the dashboard, names the project and carries an unsubscribe link', function (): void {
    $project = createReminderProjectAgo($this, 24 * 4);

    $mail = (new InstallationReminderNotification($this->owner, $this->team))->toMail($this->owner);

    expect($mail->actionUrl)->toBe(route('dashboard'))
        ->and(implode(' ', $mail->introLines))->toContain($project->name)
        ->and((string) $mail->viewData['footnote'])->toContain('/lifecycle-mails/opt-out/'.$this->owner->uuid)
        ->and($mail->replyTo)->toBe([]);
});

it('reminds the owner once to finish the setup when they never got past the team or project step', function (bool $hasUnnamedProject): void {
    ageReminderTeam($this, 24 * 4);

    if ($hasUnnamedProject) {
        Project::factory()->create(['team_id' => $this->team->id, 'name' => '']);
    }

    $this->artisan('app:send-lifecycle-mails')->assertSuccessful();
    $this->artisan('app:send-lifecycle-mails')->assertSuccessful();

    Notification::assertSentToTimes($this->owner, SetupReminderNotification::class, 1);
    Notification::assertNotSentTo($this->owner, InstallationReminderNotification::class);
})->with([
    'stopped at the team step' => false,
    'stopped at the project step' => true,
]);

it('does not send a setup reminder before three days or after a week', function (int $hours): void {
    ageReminderTeam($this, $hours);

    $this->artisan('app:send-lifecycle-mails');

    Notification::assertNotSentTo($this->owner, SetupReminderNotification::class);
})->with([
    'two days old' => 24 * 2,
    'older than a week' => 24 * 8,
]);

it('does not send setup or feedback mails to users who signed up to join another team', function (string $via): void {
    ageReminderTeam($this, 24 * 30);
    $otherTeam = User::factory()->withPersonalTeam()->create()->currentTeam;

    $via === 'membership'
        ? $otherTeam->users()->attach($this->owner, ['role' => 'editor'])
        : $otherTeam->teamInvitations()->create(['email' => $this->owner->email, 'role' => 'editor']);

    $this->artisan('app:send-lifecycle-mails');

    Notification::assertNotSentTo($this->owner, NoDataFeedbackNotification::class);
    Notification::assertNotSentTo($this->owner, SetupReminderNotification::class);
})->with(['membership', 'pending invitation']);

it('asks owners that never sent any data for feedback once, also long after signup', function (): void {
    ageReminderTeam($this, 24 * 400);

    $this->artisan('app:send-lifecycle-mails');
    $this->artisan('app:send-lifecycle-mails');

    Notification::assertSentToTimes($this->owner, NoDataFeedbackNotification::class, 1);
});

it('does not ask for feedback before ten days or when another owned team has data', function (int $days, bool $otherTeamHasData): void {
    ageReminderTeam($this, 24 * $days);

    if ($otherTeamHasData) {
        Team::factory()->create(['user_id' => $this->owner->id, 'personal_team' => false, 'events_count_overall' => 10]);
    }

    $this->artisan('app:send-lifecycle-mails');

    Notification::assertNotSentTo($this->owner, NoDataFeedbackNotification::class);
})->with([
    'nine days old' => [9, false],
    'other team has data' => [30, true],
]);

it('sends the feedback question personally when a founder is configured', function (): void {
    config(['mail.founder' => ['address' => 'founder@example.com', 'name' => 'Jane']]);

    $mail = (new NoDataFeedbackNotification($this->owner, $this->team))->toMail($this->owner);

    expect($mail->replyTo)->toBe([['founder@example.com', 'Jane']])
        ->and($mail->from)->toBe([config('mail.from.address'), 'Jane from '.config('app.name')])
        ->and((string) $mail->salutation)->toContain('Jane');
});

it('tells owners once when their data stopped arriving two weeks ago', function (): void {
    $this->team->update(['events_count_overall' => 100]);
    $project = createReminderProjectAgo($this, 24 * 90);
    createVisitorDaysAgo($this->team, $project, 20);

    $this->artisan('app:send-lifecycle-mails');
    $this->artisan('app:send-lifecycle-mails');

    Notification::assertSentToTimes($this->owner, DataStoppedNotification::class, 1);
    Notification::assertSentTo(
        $this->owner,
        DataStoppedNotification::class,
        fn (DataStoppedNotification $notification): bool => $notification->lastVisitAt()->isSameDay(now()->subDays(20)),
    );
});

it('does not send the data stopped mail for recent data or long dead teams', function (int $days): void {
    $this->team->update(['events_count_overall' => 100]);
    $project = createReminderProjectAgo($this, 24 * 200);
    createVisitorDaysAgo($this->team, $project, $days);

    $this->artisan('app:send-lifecycle-mails');

    Notification::assertNotSentTo($this->owner, DataStoppedNotification::class);
})->with([
    'data three days ago' => 3,
    'quiet for 90 days' => 90,
]);

it('lists due mails without sending them in pretend mode', function (): void {
    createReminderProjectAgo($this, 24 * 4);

    $this->artisan('app:send-lifecycle-mails --pretend')
        ->expectsOutputToContain('installation_reminder')
        ->assertSuccessful();

    Notification::assertNothingSent();
    expect($this->team->lifecycleMails()->exists())->toBeFalse();
});

it('unsubscribes through the signed link with a one-click post', function (): void {
    $url = URL::signedRoute('lifecycle-mails.opt-out.create', ['user' => $this->owner]);

    $this->get($url)->assertOk()->assertSee('Unsubscribe');
    $this->post($url)->assertRedirect($url);

    expect($this->owner->fresh()->lifecycle_mails_opted_out_at)->not->toBeNull();
    $this->get($url)->assertOk()->assertSee('You are unsubscribed');
});

it('rejects unsigned opt-out links', function (): void {
    $this->post(route('lifecycle-mails.opt-out.store', ['user' => $this->owner]))->assertForbidden();

    expect($this->owner->fresh()->lifecycle_mails_opted_out_at)->toBeNull();
});

it('sends at most one lifecycle mail per team within three days', function (): void {
    ageReminderTeam($this, 24 * 30);
    createReminderProjectAgo($this, 24 * 4);

    $this->artisan('app:send-lifecycle-mails');

    Notification::assertSentToTimes($this->owner, InstallationReminderNotification::class, 1);
    Notification::assertNotSentTo($this->owner, NoDataFeedbackNotification::class);

    $this->travel(4)->days();
    $this->artisan('app:send-lifecycle-mails');

    Notification::assertSentToTimes($this->owner, NoDataFeedbackNotification::class, 1);
});

it('does not send the installation reminder after the setup reminder', function (): void {
    $this->team->lifecycleMails()->create(['mail' => SetupReminderNotification::key(), 'sent_at' => now()->subDays(4)]);
    createReminderProjectAgo($this, 24 * 4);

    $this->artisan('app:send-lifecycle-mails');

    Notification::assertNotSentTo($this->owner, InstallationReminderNotification::class);
});

it('does not send the data stopped mail to teams at their event limit', function (): void {
    $this->mock(SubscriptionService::class)->shouldReceive('hasReachedMonthlyEventLimit')->andReturnTrue();
    $this->team->update(['events_count_overall' => 100]);
    $project = createReminderProjectAgo($this, 24 * 90);
    createVisitorDaysAgo($this->team, $project, 20);

    $this->artisan('app:send-lifecycle-mails');

    Notification::assertNotSentTo($this->owner, DataStoppedNotification::class);
});
