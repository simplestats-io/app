<?php

namespace App\Console\Commands;

use App\Models\Project;
use App\Services\StatsIncrementService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Views are incremented into cached buckets while a lazy fill may be computing the same
 * bucket, so a delta can get lost or land twice. Dropping yesterday's buckets once the day
 * is over heals that race, and also picks up batches that arrived late through client retries.
 */
class RefreshPageViewAggregations extends Command
{
    protected $signature = 'app:refresh-page-view-aggregations';

    protected $description = 'Drops the cached buckets of yesterday for projects with page views, so they are recounted from the raw data';

    public function handle(StatsIncrementService $increments): void
    {
        $projects = Project::withoutGlobalScopes()
            ->where('page_views_seen_at', '>=', now()->subDays(2)->toDateString())
            ->with('team:id,timezone')
            ->get(['id', 'team_id']);

        foreach ($projects as $project) {
            $yesterday = CarbonImmutable::now($project->team->timezone ?? config('app.timezone'))->subDay()->startOfDay();

            $hours = collect(range(0, 23))
                ->map(fn (int $hour): string => $yesterday->addHours($hour)->format('Y-m-d H:i:s'))
                ->all();

            $increments->invalidateViewBuckets($project->team_id, $project->id, $hours);
        }

        $this->info("Refreshed page view buckets of {$projects->count()} projects.");
    }
}
