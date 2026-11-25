<?php

namespace App\Jobs;

use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Laravel\Jetstream\Contracts\DeletesUsers;

class DeleteUserPermanently implements ShouldQueue
{
    use Queueable;

    // The nightly purge may have removed the user before this job runs.
    public bool $deleteWhenMissingModels = true;

    // Deleting the owned teams cascades through every stats table, which can take
    // long for large teams, so it runs on the low queue with its one hour timeout.
    public function __construct(public User $user)
    {
        $this->onQueue('low');
    }

    public function handle(DeletesUsers $deletesUsers): void
    {
        // Restored (app:restore-user) while the job waited in the queue.
        if (! $this->user->trashed()) {
            return;
        }

        $deletesUsers->delete($this->user);
    }
}
