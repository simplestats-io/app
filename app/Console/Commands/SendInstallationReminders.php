<?php

namespace App\Console\Commands;

use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use App\Notifications\InstallationReminderNotification;
use App\Notifications\SetupReminderNotification;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

class SendInstallationReminders extends Command
{
    protected $signature = 'app:send-installation-reminders';

    protected $description = 'Reminds team owners who never finished the setup or never sent any data';

    // The welcome mail already covers the installation right after signup, so the
    // reminder only follows once the owner had a few days to act on it.
    private const int REMIND_AFTER_DAYS = 3;

    // Teams that went quiet long before this command existed are not worth a
    // "you're almost there" mail anymore.
    private const int REMIND_WITHIN_DAYS = 7;

    public function handle(): void
    {
        $window = [now()->subDays(self::REMIND_WITHIN_DAYS), now()->subDays(self::REMIND_AFTER_DAYS)];

        // The project is created unnamed by the team step and only gets its name in
        // the project step, so an unnamed project still means an unfinished setup.
        $namedProjects = fn (Builder $query): Builder => $query->withoutGlobalScopes()->where('name', '<>', '');

        Team::query()
            ->where('events_count_overall', 0)
            ->whereNull('installation_reminder_sent_at')
            ->where(function (Builder $query) use ($window, $namedProjects): void {
                $query
                    ->whereHas('projects', fn (Builder $query): Builder => $namedProjects($query)->whereBetween('created_at', $window))
                    ->orWhere(fn (Builder $query): Builder => $query
                        ->where('personal_team', true)
                        ->whereBetween('teams.created_at', $window)
                        ->whereDoesntHave('projects', $namedProjects)
                        // Invited users get a personal team on the SaaS too, but they
                        // signed up to join another team, not to set up their own.
                        // Their invitation may still be pending until they accept it.
                        ->whereDoesntHave('owner.teams')
                        ->whereDoesntHave('owner', fn (Builder $query): Builder => $query->whereIn('email', TeamInvitation::query()->select('email'))));
            })
            ->with('owner')
            // lazyById, not each: the loop sets installation_reminder_sent_at, so offset
            // paging would skip rows once they drop out of the filtered result.
            ->lazyById()
            ->each(function (Team $team): void {
                /** @var User|null $owner */
                $owner = $team->owner;

                // A soft-deleted owner resolves to null and must not be mailed.
                if (! $owner?->hasVerifiedEmail()) {
                    return;
                }

                $project = $team->projects()->withoutGlobalScopes()->where('name', '<>', '')->oldest()->first();

                $owner->notify($project
                    ? new InstallationReminderNotification($owner, $project)
                    : new SetupReminderNotification($owner));

                $team->touch('installation_reminder_sent_at');
            });
    }
}
