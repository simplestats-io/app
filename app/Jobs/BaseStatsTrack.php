<?php

namespace App\Jobs;

use App\Models\Project;
use App\Models\StatsVisitor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Context;

abstract class BaseStatsTrack implements ShouldQueue
{
    use Queueable;

    protected ?Project $project = null;

    /**
     * Create a new job instance.
     */
    public function __construct(
        protected array $input,
    ) {
        $this->onQueue('ingest');
    }

    public function tags(): array
    {
        $tags = [
            'StatsTrack',
        ];

        if ($projectId = Context::get('project_id')) {
            $tags[] = 'Project:'.$projectId;
        }

        if (isset($this->input['id'])) {
            $tags[] = 'ID:'.$this->input['id'];
        }

        if (isset($this->input['ip'])) {
            $tags[] = 'IP:'.$this->input['ip'];
        }

        return $tags;
    }

    public function handle(): void
    {
        $this->project = Project::withoutGlobalScope('team')->findOrFail(Context::get('project_id'));

        auth()->setUser($this->project);
    }

    protected function resolveVisitorHash(): void
    {
        if (isset($this->input['visitor_hash'])) {
            $this->input['stats_visitor_id'] = StatsVisitor::where('visitor_hash', $this->input['visitor_hash'])->first()?->id ?? null;
            unset($this->input['visitor_hash']);
        }
    }
}
