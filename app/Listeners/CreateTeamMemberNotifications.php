<?php

namespace App\Listeners;

use App\Actions\CreateUserNotification;
use App\Models\Project;
use Laravel\Jetstream\Events\TeamMemberAdded;

class CreateTeamMemberNotifications
{
    public function handle(TeamMemberAdded $event): void
    {
        // The accepting user's current team is not the team they join, so the
        // global scopes (current team, their project access) must not narrow
        // this down; CreateUserNotification applies the new member's access.
        Project::query()
            ->withoutGlobalScopes()
            ->whereBelongsTo($event->team)
            ->get()
            ->each(fn (Project $project) => resolve(CreateUserNotification::class)($event->user, $project));
    }
}
