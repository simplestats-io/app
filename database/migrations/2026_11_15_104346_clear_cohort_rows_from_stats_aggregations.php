<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Registration-cohort rows used to be stored without a marker in `filter`, so
     * they were incremented like the plain row of the same dimensions. They cannot
     * be told apart afterwards, except that they share every stored column but the
     * hash with that plain row. Dropping such groups lets them be recomputed.
     */
    public function up(): void
    {
        DB::statement(<<<'SQL'
            DELETE FROM stats_aggregations
            WHERE id IN (
                SELECT id FROM (
                    SELECT id, COUNT(*) OVER (
                        PARTITION BY team_id, project_id, type, kind, time, filter::text
                    ) AS duplicates
                    FROM stats_aggregations
                    WHERE filter IS NOT NULL
                ) AS grouped
                WHERE duplicates > 1
            )
        SQL);
    }
};
