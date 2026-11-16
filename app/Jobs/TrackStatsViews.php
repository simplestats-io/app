<?php

namespace App\Jobs;

use App\Services\StatsViewIngestService;

class TrackStatsViews extends BaseStatsTrack
{
    /**
     * @return list<string>
     */
    public function tags(): array
    {
        return [...parent::tags(), 'Batch:'.$this->input['batch_id']];
    }

    public function handle(): void
    {
        parent::handle();

        resolve(StatsViewIngestService::class)->ingest(
            project: $this->project,
            batchId: $this->input['batch_id'],
            items: $this->input['items'],
            mode: $this->input['mode'] ?? null,
        );
    }
}
