<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stats_visitor_pages', function (Blueprint $table): void {
            $table->unsignedBigInteger('stats_visitor_id');
            $table->unsignedBigInteger('stats_page_id');
            $table->unsignedBigInteger('team_id');
            $table->unsignedBigInteger('project_id');
            $table->timestamp('time');
            $table->unsignedInteger('views');

            $table->primary(['stats_visitor_id', 'stats_page_id']);
            $table->index(['team_id', 'project_id', 'time']);
            $table->index(['team_id', 'time']);

            $table->foreign('stats_visitor_id')
                ->references('id')
                ->on('stats_visitors')
                ->onDelete('cascade');

            $table->foreign('stats_page_id')
                ->references('id')
                ->on('stats_pages')
                ->onDelete('cascade');
        });

        DB::statement('ALTER TABLE stats_visitor_pages SET (fillfactor = 85)');
    }
};
