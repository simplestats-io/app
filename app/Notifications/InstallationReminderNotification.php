<?php

namespace App\Notifications;

use App\Models\Project;
use App\Models\User;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class InstallationReminderNotification extends Notification
{
    public function __construct(
        public User $user,
        public Project $project,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $appName = config('app.name');

        return (new MailMessage)
            ->subject("You're one step away from your first {$appName} insights")
            ->greeting("Hi {$this->user->name},")
            ->line("you created **{$this->project->name}** on {$appName}, but it has not sent any data yet. Only the client installation is missing:")
            ->line('1. `composer require simplestats-io/laravel-client`')
            ->line('2. Publish the config and add your API token to the `.env` file')
            ->line('3. Open your app in a browser, the first visit shows up right away')
            ->action('Finish the setup', route('dashboard'))
            ->line('Your dashboard shows all steps including your API token and updates live as soon as the first data arrives.')
            ->line('Stuck somewhere? [Chat with us](https://tawk.to/chat/6601f04ea0c6737bd124880f/1hprp5d8g) or [book a quick call](https://calendly.com/simplestats/30min), we are happy to help.');
    }
}
