<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Notifications\UserDeletedNotification;
use App\Notifications\UserInactiveNotification;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

class CheckInactiveUsers extends Command
{
    protected $signature = 'app:check-inactive-users';

    protected $description = 'Marks inactive users for deletion after the warning period';

    private const int INACTIVE_AFTER_DAYS = 50;

    public function handle(): void
    {
        // first mark already warned users, so that they do not get another notification
        $this->markInactiveUsers();

        // notify inactive users
        $this->notifyInactiveUsers();
    }

    private function markInactiveUsers(): void
    {
        // Soft delete only: the user, their teams and stats stay intact for the
        // grace window so a wrongly-flagged account can be restored without a backup.
        // The SoftDeletes scope hides already-marked users from these queries; the
        // real cascading delete runs later in app:purge-pending-deleted-users.
        $this->inactiveCandidates()
            ->where('inactive_since', '<', now()->subDays(10))
            ->each(function (User $user): void {
                $user->notify(new UserDeletedNotification($user));
                $user->delete();
            });
    }

    private function notifyInactiveUsers(): void
    {
        $this->inactiveCandidates()
            ->where('last_login_at', '<', now()->subDays(self::INACTIVE_AFTER_DAYS))
            ->whereNull('inactive_since')
            ->each(function (User $user): void {
                $user->notify(new UserInactiveNotification($user, 10));
                $user->update(['inactive_since' => now()]);
            });

        $this->inactiveCandidates()
            ->where('inactive_since', '<', now()->subDays(9))
            ->each(function (User $user): void {
                $user->notify(new UserInactiveNotification($user, 1));
            });
    }

    /**
     * Users that may be inactive: no paid subscription, and no owned team that
     * someone else is still using.
     *
     * Deleting an owner is never limited to that owner: DeleteUser purges every
     * team they own, which detaches all members and takes the projects and stats
     * with it. Only the owner is ever warned, so an owner who left the company
     * would silently take an actively used team down with them.
     */
    private function inactiveCandidates(): Builder
    {
        return User::query()
            ->whereDoesntHave('subscriptions', function (\Illuminate\Contracts\Database\Query\Builder $query): void {
                $query->whereIn('status', ['active', 'past_due']);
            })
            ->whereDoesntHave('ownedTeams.users', function (\Illuminate\Contracts\Database\Query\Builder $query): void {
                $query->where('users.last_login_at', '>', now()->subDays(self::INACTIVE_AFTER_DAYS));
            });
    }
}
