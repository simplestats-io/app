<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Views whose visitor hash was unknown when their batch arrived. A batch can overtake
     * the visitor event (separate client job, client backoff), so they are retried for a
     * while before they are dropped.
     */
    public function up(): void
    {
        Schema::create('stats_views_pending', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('project_id');
            $table->uuid('batch_id');
            $table->string('visitor_hash', 64);
            $table->string('page');
            $table->unsignedInteger('views');
            $table->timestamp('last_viewed_at');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['project_id', 'batch_id']);
            $table->index('created_at');

            $table->foreign('project_id')
                ->references('id')
                ->on('projects')
                ->onDelete('cascade');
        });
    }
};
