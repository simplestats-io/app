<?php

namespace App\Listeners;

use App\Actions\CreateUserNotification;
use App\Models\Project;
use App\Models\UserNotification;
use Illuminate\Database\Eloquent\Builder;
use Laravel\Jetstream\Events\TeamMemberUpdated;

/**
 * Keeps report and alert rows in line with the member's project access after
 * their role or project selection changed: rows for projects they lost access
 * to go away, projects they gained get their default notifications.
 */
class SyncTeamMemberNotifications
{
    public function handle(TeamMemberUpdated $event): void
    {
        // The updater may manage a team other than their current one, so the team
        // and project access scopes of the acting user must not narrow this down.
        $projects = Project::query()
            ->withoutGlobalScopes()
            ->whereBelongsTo($event->team)
            ->get();

        $accessibleProjectIds = $projects
            ->filter(fn (Project $project): bool => $event->user->canAccessProject($project))
            ->modelKeys();

        UserNotification::whereBelongsTo($event->user)
            ->whereHasMorph(
                'notifiable',
                Project::class,
                fn (Builder $query): Builder => $query
                    ->withoutGlobalScopes()
                    ->whereBelongsTo($event->team)
                    ->whereKeyNot($accessibleProjectIds),
            )
            ->delete();

        $projects
            ->filter(fn (Project $project): bool => in_array($project->id, $accessibleProjectIds, true))
            ->each(fn (Project $project) => resolve(CreateUserNotification::class)($event->user, $project));
    }
}
