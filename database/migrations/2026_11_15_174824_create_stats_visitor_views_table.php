<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per visitor-day. team_id, project_id and time are copied from stats_visitors
     * so the stats groups can range-scan this table and hash join it instead of doing one
     * primary key lookup per visitor.
     */
    public function up(): void
    {
        Schema::create('stats_visitor_views', function (Blueprint $table): void {
            $table->unsignedBigInteger('stats_visitor_id')->primary();
            $table->unsignedBigInteger('team_id');
            $table->unsignedBigInteger('project_id');
            $table->timestamp('time');
            $table->timestamp('last_viewed_at');
            $table->unsignedInteger('views');

            $table->index(['team_id', 'project_id', 'time']);
            $table->index(['team_id', 'time']);

            $table->foreign('stats_visitor_id')
                ->references('id')
                ->on('stats_visitors')
                ->onDelete('cascade');
        });

        // views and last_viewed_at are in no index, the headroom keeps their updates HOT.
        DB::statement('ALTER TABLE stats_visitor_views SET (fillfactor = 85)');
    }
};
