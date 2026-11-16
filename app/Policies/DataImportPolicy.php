<?php

namespace App\Policies;

use App\Models\DataImport;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class DataImportPolicy
{
    use HandlesAuthorization;

    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, DataImport $dataImport): bool
    {
        return true;
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $user->hasTeamPermission($user->currentTeam, 'action:importPermission')
            && $user->currentTeam->owner->sparkPlan()->options[DataImport::class];
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, DataImport $dataImport): bool
    {
        return $user->hasTeamPermission($dataImport->team, 'action:importPermission')
            && $user->currentTeam->owner->sparkPlan()->options[DataImport::class];
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, DataImport $dataImport): bool
    {
        return $user->hasTeamPermission($dataImport->team, 'action:importPermission')
            && $user->currentTeam->owner->sparkPlan()->options[DataImport::class];
    }

    /**
     * Determine whether the user can cancel the model.
     */
    public function cancel(User $user, DataImport $dataImport): bool
    {
        return $user->hasTeamPermission($dataImport->team, 'action:importPermission')
            && $user->currentTeam->owner->sparkPlan()->options[DataImport::class];
    }

    /**
     * Determine whether the user can rollback the import.
     */
    public function rollback(User $user, DataImport $dataImport): bool
    {
        return $dataImport->type->canRollBack()
            && $user->hasTeamPermission($dataImport->team, 'action:importPermission')
            && $user->currentTeam->owner->sparkPlan()->options[DataImport::class];
    }
}
