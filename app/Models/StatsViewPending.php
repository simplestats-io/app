<?php

namespace App\Models;

use App\Concerns\HasProjectAccessScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;

class StatsViewPending extends Model
{
    use HasProjectAccessScope;
    use MassPrunable;

    // The resolve attempts end after about 107 minutes, older rows lost their resolve job.
    public const int RETENTION_HOURS = 3;

    protected $table = 'stats_views_pending';

    public $timestamps = false;

    /**
     * @return Builder<self>
     */
    public function prunable(): Builder
    {
        return static::where('created_at', '<', now()->subHours(self::RETENTION_HOURS));
    }
}
