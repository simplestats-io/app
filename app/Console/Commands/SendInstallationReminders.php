<?php

namespace App\Console\Commands;

use App\Models\Team;
use App\Models\User;
use App\Notifications\InstallationReminderNotification;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

class SendInstallationReminders extends Command
{
    protected $signature = 'app:send-installation-reminders';

    protected $description = 'Reminds team owners who created a project but never sent any data to finish the installation';

    // The welcome mail already covers the installation right after signup, so the
    // reminder only follows once the owner had a few days to act on it.
    private const int REMIND_AFTER_DAYS = 3;

    // Teams that went quiet long before this command existed are not worth a
    // "you're almost there" mail anymore.
    private const int REMIND_WITHIN_DAYS = 7;

    public function handle(): void
    {
        Team::query()
            ->where('events_count_overall', 0)
            ->whereNull('installation_reminder_sent_at')
            ->whereHas('projects', function (Builder $query): void {
                $query->withoutGlobalScopes()->whereBetween('created_at', [
                    now()->subDays(self::REMIND_WITHIN_DAYS),
                    now()->subDays(self::REMIND_AFTER_DAYS),
                ]);
            })
            ->with('owner')
            ->each(function (Team $team): void {
                /** @var User|null $owner */
                $owner = $team->owner;

                // A soft-deleted owner resolves to null and must not be mailed.
                if (! $owner?->hasVerifiedEmail()) {
                    return;
                }

                $project = $team->projects()->withoutGlobalScopes()->oldest()->first();

                $owner->notify(new InstallationReminderNotification($owner, $project));
                $team->touch('installation_reminder_sent_at');
            });
    }
}
