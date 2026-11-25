<?php

namespace App\Actions\Jetstream;

use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Jetstream\Contracts\DeletesTeams;
use Laravel\Jetstream\Contracts\DeletesUsers;

class DeleteUser implements DeletesUsers
{
    /**
     * The team deleter implementation.
     *
     * @var DeletesTeams
     */
    protected $deletesTeams;

    /**
     * Create a new action instance.
     */
    public function __construct(DeletesTeams $deletesTeams)
    {
        $this->deletesTeams = $deletesTeams;
    }

    /**
     * Delete the given user.
     */
    public function delete(User $user): void
    {
        DB::transaction(function () use ($user): void {
            $this->deleteTeams($user);
            $user->deleteProfilePhoto();
            $user->connectedAccounts->each->delete();
            $user->tokens->each->delete();

            if (config('app.app_type') === 'saas' && ($user->subscription()?->recurring() || $user->subscription()?->pastDue())) {
                $user->subscription()->cancelNow();
            }

            // forceDelete (not soft): this is the real, irreversible removal used by
            // self-serve deletion, the admin panel and the post-grace purge job. Only
            // the purge archives the user, see UserBase::archiveOnForceDelete().
            $user->forceDelete();
        });
    }

    /**
     * Delete the teams and team associations attached to the user.
     */
    protected function deleteTeams(User $user): void
    {
        $user->teams()->detach();

        $user->ownedTeams->each(function (Team $team): void {
            $this->deletesTeams->delete($team);
        });
    }
}
