<?php

use App\Models\Project;
use App\Models\StatsAggregation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Visitors can no longer be switched off (ProjectRequest forces the bit). The
     * client package kept sending them anyway, so these projects already have the
     * data; setting the bit only makes it visible again. Updated per model so
     * ProjectObserver adds the visitors key to report settings, and the cached
     * aggregations built without the bit are dropped.
     */
    public function up(): void
    {
        $projects = Project::withoutGlobalScopes()
            ->whereRaw('(usage & ?) = 0', [Project::USAGE_VISITORS])
            ->get();

        if ($projects->isEmpty()) {
            return;
        }

        $projects->each(fn (Project $project) => $project->update(['usage' => $project->usage | Project::USAGE_VISITORS]));

        StatsAggregation::withoutGlobalScopes()
            ->whereIn('project_id', $projects->modelKeys())
            ->orWhere(fn (Builder $query) => $query
                ->whereNull('project_id')
                ->whereIn('team_id', $projects->pluck('team_id')->unique()))
            ->delete();
    }
};
