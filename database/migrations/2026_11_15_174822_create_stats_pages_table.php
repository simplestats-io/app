<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Page lookup for page views. Kept apart from stats_page_entries so the entry page
     * group does not grow with every page a visitor merely views.
     */
    public function up(): void
    {
        Schema::create('stats_pages', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('team_id');
            $table->string('name');

            $table->unique(['team_id', 'name']);

            $table->foreign('team_id')
                ->references('id')
                ->on('teams')
                ->onDelete('cascade');
        });
    }
};
