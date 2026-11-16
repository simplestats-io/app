<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Idempotency markers for page view batches. The marker is written in the same
     * transaction as the counters, so a retried batch is either fully counted or not at all.
     */
    public function up(): void
    {
        Schema::create('stats_view_batches', function (Blueprint $table): void {
            $table->unsignedBigInteger('project_id');
            $table->uuid('batch_id');
            $table->unsignedInteger('views');
            $table->unsignedInteger('matched_views');
            $table->string('mode', 16)->nullable();
            $table->timestamp('received_at')->useCurrent();
            // Cache increments run after the commit, a retry finding this NULL knows they were lost.
            $table->timestamp('cache_applied_at')->nullable();

            $table->primary(['project_id', 'batch_id']);
            $table->index('received_at');

            $table->foreign('project_id')
                ->references('id')
                ->on('projects')
                ->onDelete('cascade');
        });
    }
};
