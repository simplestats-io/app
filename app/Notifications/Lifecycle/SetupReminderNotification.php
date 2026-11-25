<?php

namespace App\Notifications\Lifecycle;

use App\Models\Team;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Notifications\Messages\MailMessage;

class SetupReminderNotification extends LifecycleNotification
{
    public static function key(): string
    {
        return 'setup_reminder';
    }

    /**
     * The welcome mail already covers the setup right after signup, so the reminder
     * follows after three days. Teams that went quiet long before are not worth a
     * "you're almost there" mail anymore, hence the one week cap.
     */
    public static function audience(): Builder
    {
        return static::withoutInvitedOwners(Team::query()
            ->where('events_count_overall', 0)
            ->where('personal_team', true)
            ->whereBetween('teams.created_at', [now()->subDays(7), now()->subDays(3)])
            ->whereDoesntHave('projects', static::namedProjects(...)));
    }

    protected function compose(MailMessage $mail): MailMessage
    {
        $appName = config('app.name');

        return $mail
            ->subject("Your {$appName} setup is waiting for you")
            ->line("thanks for signing up for {$appName}! Your setup is not finished yet, and it only takes a few minutes:")
            ->line('1. Set up your team and name your project')
            ->line('2. Install the Laravel client, your first visitors show up right away')
            ->action('Finish the setup', route('dashboard'))
            ->line('Stuck somewhere? [Chat with us](https://tawk.to/chat/6601f04ea0c6737bd124880f/1hprp5d8g) or [book a quick call](https://calendly.com/simplestats/30min), we are happy to help.');
    }
}
