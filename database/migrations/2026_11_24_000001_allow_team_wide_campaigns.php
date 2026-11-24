<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A campaign without project_id spans all projects of its team. The existing
     * (project_id, name) unique index ignores NULLs, so team-wide names get their own.
     */
    public function up(): void
    {
        Schema::table('campaigns', function (Blueprint $table): void {
            $table->unsignedBigInteger('project_id')->nullable()->change();
        });

        DB::statement('CREATE UNIQUE INDEX campaigns_team_id_name_all_projects_unique ON campaigns (team_id, name) WHERE project_id IS NULL');
    }
};
