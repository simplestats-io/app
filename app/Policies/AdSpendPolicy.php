<?php

namespace App\Policies;

use App\Models\AdSpend;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class AdSpendPolicy
{
    use HandlesAuthorization;

    public function update(User $user, AdSpend $adSpend): bool
    {
        return $adSpend->campaign !== null && $user->can('update', $adSpend->campaign);
    }

    public function delete(User $user, AdSpend $adSpend): bool
    {
        return $adSpend->campaign !== null && $user->can('update', $adSpend->campaign);
    }
}
