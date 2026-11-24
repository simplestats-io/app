<?php

use App\Models\StatsAggregation;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Cached buckets built with a campaign filter still count the traffic of every
     * project that shares the campaign's utm values. They refill lazily with the
     * corrected per-project numbers on the next dashboard load.
     */
    public function up(): void
    {
        StatsAggregation::query()
            ->whereRaw("filter::text LIKE '%\"campaign\"%'")
            ->whereRaw("COALESCE((filter::jsonb ->> 'campaign')::bigint, 0) <> 0")
            ->delete();
    }
};
