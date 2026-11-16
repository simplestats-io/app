<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * page_views_since separates "no views" from "not tracked yet" for older ranges.
     * page_views_seen_at drives the visibility rule (views in the last 30 days).
     */
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table): void {
            $table->timestamp('page_views_since')->nullable();
            $table->date('page_views_seen_at')->nullable();
        });
    }
};
