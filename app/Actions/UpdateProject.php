<?php

namespace App\Actions;

use App\Enums\DashboardPreset;
use App\Models\Project;
use App\Models\User;
use App\Services\StatsAggregationsService;
use Illuminate\Support\Facades\Gate;

class UpdateProject
{
    public function __invoke(User $user, Project $project, array $input): Project
    {
        Gate::forUser($user)->authorize('update', $project);

        if (isset($input['usage']) && $input['usage'] != $project->usage) {
            resolve(StatsAggregationsService::class)->clearByProject($project);
            $user->updateSettings('dashboardPreset', DashboardPreset::ADVANCED->value);
        }

        return tap($project)->update($input);
    }
}
