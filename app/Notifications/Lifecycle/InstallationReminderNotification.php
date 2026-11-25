<?php

namespace App\Notifications\Lifecycle;

use App\Models\Project;
use App\Models\Team;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Notifications\Messages\MailMessage;

class InstallationReminderNotification extends LifecycleNotification
{
    public static function key(): string
    {
        return 'installation_reminder';
    }

    /**
     * Owners get one of the two onboarding reminders, never both.
     */
    public static function audience(): Builder
    {
        return Team::query()
            ->where('events_count_overall', 0)
            ->whereDoesntHave('lifecycleMails', fn (Builder $query): Builder => $query->where('mail', SetupReminderNotification::key()))
            ->whereHas('projects', fn (Builder $query): Builder => static::namedProjects($query)
                ->whereBetween('created_at', [now()->subDays(7), now()->subDays(3)]));
    }

    public function project(): ?Project
    {
        return static::namedProjects($this->team->projects()->getQuery())->oldest()->first();
    }

    protected function compose(MailMessage $mail): MailMessage
    {
        $appName = config('app.name');
        $projectName = $this->project()->name ?? 'your project';

        return $mail
            ->subject("You're one step away from your first {$appName} insights")
            ->line("you created **{$projectName}** on {$appName}, but it has not sent any data yet. Only the client installation is missing:")
            ->line('1. `composer require simplestats-io/laravel-client`')
            ->line('2. Publish the config and add your API token to the `.env` file')
            ->line('3. Open your app in a browser, the first visit shows up right away')
            ->action('Finish the setup', route('dashboard'))
            ->line('Your dashboard shows all steps including your API token and updates live as soon as the first data arrives.')
            ->line('Stuck somewhere? [Chat with us](https://tawk.to/chat/6601f04ea0c6737bd124880f/1hprp5d8g) or [book a quick call](https://calendly.com/simplestats/30min), we are happy to help.');
    }
}
