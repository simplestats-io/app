<?php

namespace App\Actions;

use App\Contracts\NotifiableToUser;
use App\Models\Project;
use App\Models\User;
use App\Models\UserNotification;
use App\ValueObjects\UserNotificationTypeObject;
use Illuminate\Database\Eloquent\Model;

class CreateUserNotification
{
    public function __invoke(User $user, NotifiableToUser|Model $notifiable): void
    {
        // Reports and alerts go to whoever has a row here, so a member limited to
        // selected projects must never get one for a project outside that selection.
        if ($notifiable instanceof Project && ! $user->canAccessProject($notifiable)) {
            return;
        }

        $notifiable->getDefaultUserNotificationTypes()
            ->each(function (UserNotificationTypeObject $notificationType) use ($user, $notifiable): void {
                UserNotification::firstOrCreate(
                    [
                        'user_id' => $user->id,
                        'notifiable_id' => $notifiable->id,
                        'notifiable_type' => $notifiable::class,
                        'type' => $notificationType->type,
                    ],
                    [
                        'frequency' => $notificationType->frequency?->value,
                        'settings' => $notificationType->settings,
                    ],
                );
            });
    }
}
