<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class SetupReminderNotification extends Notification
{
    public function __construct(
        public User $user,
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
            ->subject("Your {$appName} setup is waiting for you")
            ->greeting("Hi {$this->user->name},")
            ->line("thanks for signing up for {$appName}! Your setup is not finished yet, and it only takes a few minutes:")
            ->line('1. Set up your team and name your project')
            ->line('2. Install the Laravel client, your first visitors show up right away')
            ->action('Finish the setup', route('dashboard'))
            ->line('Stuck somewhere? [Chat with us](https://tawk.to/chat/6601f04ea0c6737bd124880f/1hprp5d8g) or [book a quick call](https://calendly.com/simplestats/30min), we are happy to help.');
    }
}
