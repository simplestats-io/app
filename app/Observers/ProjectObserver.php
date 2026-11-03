<?php

namespace App\Observers;

use App\Actions\CreateUserNotification;
use App\Models\Membership;
use App\Models\Project;
use App\Models\TeamInvitation;
use App\Models\UserNotification;
use App\ValueObjects\UserNotificationTypeObject;
use Illuminate\Support\Arr;

class ProjectObserver
{
    /**
     * Handle the Project "created" event.
     */
    public function created(Project $project): void
    {
        Project::with(['team.users', 'team.owner'])->find($project->id)->team->allUsers()
            ->each(function ($user) use ($project): void {
                resolve(CreateUserNotification::class)($user, $project);
            });
    }

    /**
     * Handle the Project "updated" event.
     */
    public function updated(Project $project): void
    {
        if ($project->wasChanged('usage')) {
            $project->getDefaultUserNotificationTypes()->each(
                function (UserNotificationTypeObject $notificationType) use ($project): void {
                    $allowedKeys = array_keys($notificationType->settings['tracking']);

                    $project->userNotifications()->where('type', $notificationType->type)
                        ->each(function (UserNotification $notification) use ($allowedKeys, $notificationType): void {
                            $filteredSettings = collect($notification->settings)
                                ->only($allowedKeys)
                                ->toArray();

                            $notification->update([
                                'settings->tracking' => array_merge($notificationType->settings['tracking'], $filteredSettings),
                            ]);
                        });
                }
            );
        }
    }

    /**
     * Handle the Project "deleted" event.
     */
    public function deleted(Project $project): void
    {
        $project->userNotifications()->delete();

        $teamId = $project->team_id;
        $projectId = $project->id;

        Membership::query()
            ->select([
                'id',
                'project_ids',
            ])
            ->where('team_id', $teamId)
            ->whereJsonContains('project_ids', $projectId)
            ->each(fn (Membership $membership): bool => $membership->update([
                'project_ids' => array_values(array_diff($membership->project_ids, [$projectId])),
            ]));

        TeamInvitation::query()
            ->select([
                'id',
                'project_ids',
            ])
            ->where('team_id', $teamId)
            ->whereJsonContains('project_ids', $projectId)
            ->each(fn (TeamInvitation $invitation): bool => $invitation->update([
                'project_ids' => array_values(array_diff($invitation->project_ids, [$projectId])),
            ]));

        $project->team->allUsers()->each(function ($user) use ($teamId, $projectId): void {
            $settings = $user->settings;
            $savedProjectId = $settings['statsFilters'][$teamId]['project_id'] ?? null;

            if ($savedProjectId !== null && (int) $savedProjectId === $projectId) {
                Arr::forget($settings, "statsFilters.{$teamId}.project_id");
                $user->update(['settings' => $settings]);
            }
        });
    }
}
