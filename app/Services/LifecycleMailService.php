<?php

namespace App\Services;

use App\Models\Team;
use App\Models\User;
use App\Notifications\Lifecycle\DataStoppedNotification;
use App\Notifications\Lifecycle\InstallationReminderNotification;
use App\Notifications\Lifecycle\LifecycleNotification;
use App\Notifications\Lifecycle\NoDataFeedbackNotification;
use App\Notifications\Lifecycle\SetupReminderNotification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\LazyCollection;
use Throwable;

class LifecycleMailService
{
    /**
     * Without a gap a team can match several mails at once (above all on the first
     * run), and the owner would get them all on the same day.
     */
    private const int DAYS_BETWEEN_MAILS = 3;

    /**
     * Ordered by priority: when several mails are due, the first one wins.
     *
     * @var list<class-string<LifecycleNotification>>
     */
    public const array MAILS = [
        SetupReminderNotification::class,
        InstallationReminderNotification::class,
        NoDataFeedbackNotification::class,
        DataStoppedNotification::class,
    ];

    /**
     * @return LazyCollection<int, array{mail: class-string<LifecycleNotification>, team: Team, owner: User}>
     */
    public function due(): LazyCollection
    {
        return LazyCollection::make(function () {
            foreach (self::MAILS as $mail) {
                foreach ($this->dueTeams($mail) as $team) {
                    if ($team->owner instanceof User && (new $mail($team->owner, $team))->shouldSend()) {
                        yield ['mail' => $mail, 'team' => $team, 'owner' => $team->owner];
                    }
                }
            }
        });
    }

    /**
     * @return int number of sent mails
     */
    public function send(): int
    {
        $sent = 0;

        foreach ($this->due() as ['mail' => $mail, 'team' => $team, 'owner' => $owner]) {
            // Claiming the row first means a second run can never send the same mail twice.
            $record = $team->lifecycleMails()->createOrFirst(['mail' => $mail::key()], ['sent_at' => now()]);

            if (! $record->wasRecentlyCreated) {
                continue;
            }

            try {
                $owner->notify(new $mail($owner, $team));
                $sent++;
            } catch (Throwable $exception) {
                // The claim stays: the mail may have left before the error (e.g. a failing
                // listener), and a lost mail is better than one resent every day.
                report($exception);
            }
        }

        return $sent;
    }

    /**
     * @param  class-string<LifecycleNotification>  $mail
     * @return LazyCollection<int, Team>
     */
    private function dueTeams(string $mail): LazyCollection
    {
        return $mail::audience()
            ->whereDoesntHave('lifecycleMails', fn (Builder $query): Builder => $query
                ->where('mail', $mail::key())
                ->orWhere('sent_at', '>', now()->subDays(self::DAYS_BETWEEN_MAILS)))
            // A soft-deleted owner drops out here, the relation applies the SoftDeletes scope.
            ->whereHas('owner', fn (Builder $query): Builder => $query
                ->whereNotNull('email_verified_at')
                ->whereNull('lifecycle_mails_opted_out_at'))
            ->with('owner')
            // lazyById, not each: sending adds lifecycle_mails rows, so offset paging
            // would skip teams once they drop out of the filtered result.
            ->lazyById();
    }
}
