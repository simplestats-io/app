<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Search Console rows per project, day, keyword and landing page. Pages reuse the
     * stats_pages lookup of page views, so GSC urls never show up as entry pages.
     * `date` is the Search Console day (Pacific Time), not the team's timezone.
     */
    public function up(): void
    {
        Schema::create('stats_keyword_daily', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->foreignId('stats_track_keyword_id')->constrained()->cascadeOnDelete();
            $table->foreignId('stats_page_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('clicks')->default(0);
            $table->unsignedInteger('impressions')->default(0);
            $table->decimal('position', 7, 2)->default(0);

            $table->unique(['project_id', 'date', 'stats_track_keyword_id', 'stats_page_id'], 'stats_keyword_daily_unique');
            $table->index(['team_id', 'date']);
        });
    }
};
