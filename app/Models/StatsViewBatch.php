<?php

namespace App\Models;

use App\Concerns\HasProjectAccessScope;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;

/**
 * Idempotency marker of a page view batch. Keyed by (project_id, batch_id), so it is
 * only used through queries, never loaded and saved as a single model.
 */
class StatsViewBatch extends Model
{
    use HasProjectAccessScope;
    use MassPrunable;

    // Must outlast the client's retry ladder for page view batches (about 22 hours),
    // a retry after the marker is gone would be counted a second time.
    public const int RETENTION_HOURS = 72;

    public $timestamps = false;

    public $incrementing = false;

    protected $primaryKey = 'batch_id';

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    #[Scope]
    protected function forBatch(Builder $query, int $projectId, string $batchId): Builder
    {
        return $query->where('project_id', $projectId)->where('batch_id', $batchId);
    }

    /**
     * @return Builder<self>
     */
    public function prunable(): Builder
    {
        return static::where('received_at', '<', now()->subHours(self::RETENTION_HOURS));
    }
}
