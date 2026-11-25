<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Laravel\Jetstream\Contracts\DeletesUsers;
use Throwable;

class PurgePendingDeletedUsers extends Command
{
    protected $signature = 'app:purge-pending-deleted-users';

    protected $description = 'Hard-deletes users that were marked for deletion longer than the retention window ago';

    // Grace window between the soft mark (CheckInactiveUsers) and the irreversible,
    // cascading deletion. Long enough to notice and restore a wrongly-flagged account.
    private const int PURGE_AFTER_MONTHS = 2;

    public function handle(DeletesUsers $deletesUsers): void
    {
        User::onlyTrashed()
            ->where('deleted_at', '<', now()->subMonths(self::PURGE_AFTER_MONTHS))
            // lazyById, not each: deleting rows while paging by offset would skip users.
            ->lazyById()
            ->each(function (User $user) use ($deletesUsers): void {
                // Only accounts removed for inactivity stay reachable for announcements.
                // A trashed user without inactive_since was deleted on request (e.g. an
                // admin deletion whose queued job failed) and is erased completely.
                if ($user->inactive_since !== null) {
                    $user->archiveOnForceDelete();
                }

                try {
                    $deletesUsers->delete($user);
                } catch (Throwable $exception) {
                    report($exception);
                }
            });
    }
}
