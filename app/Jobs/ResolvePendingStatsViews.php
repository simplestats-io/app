<?php

namespace App\Jobs;

use App\Helpers\AppHelper;
use App\Models\Project;
use App\Services\StatsViewIngestService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ResolvePendingStatsViews implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public int $projectId,
        public string $batchId,
        public int $attempt,
    ) {
        $this->onQueue('ingest');
    }

    public function handle(StatsViewIngestService $ingestService): void
    {
        $project = Project::withoutGlobalScopes()->find($this->projectId);

        if (is_null($project)) {
            return;
        }

        // Same context as the ingest jobs: team-scoped models resolve through it.
        auth()->setUser($project);
        AppHelper::setTeamContextFromAuth($project);

        $ingestService->resolvePending($project, $this->batchId, $this->attempt);
    }
}
