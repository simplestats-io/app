<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // NULL grants access to every project of the team; an array limits the
        // member (or the invitee, once accepted) to those project ids.
        Schema::table('team_user', function (Blueprint $table): void {
            $table->jsonb('project_ids')->nullable()->after('role');
        });

        Schema::table('team_invitations', function (Blueprint $table): void {
            $table->jsonb('project_ids')->nullable()->after('role');
        });
    }
};
