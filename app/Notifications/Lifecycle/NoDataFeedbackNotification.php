<?php

namespace App\Notifications\Lifecycle;

use App\Models\Team;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\HtmlString;

class NoDataFeedbackNotification extends LifecycleNotification
{
    public static function key(): string
    {
        return 'no_data_feedback';
    }

    /**
     * No upper age limit on purpose: everyone who never got any data in gets the
     * question once, including accounts from before this mail existed. Keyed on the
     * personal team, so an owner with several empty teams is asked only once.
     */
    public static function audience(): Builder
    {
        return static::withoutInvitedOwners(Team::query()
            ->where('personal_team', true)
            ->where('teams.created_at', '<=', now()->subDays(10))
            ->whereDoesntHave('owner.ownedTeams', fn (Builder $query): Builder => $query->where('events_count_overall', '>', 0)));
    }

    public function isPersonal(): bool
    {
        return true;
    }

    protected function compose(MailMessage $mail): MailMessage
    {
        $appName = config('app.name');

        return $mail
            ->subject("Quick question about your {$appName} setup")
            ->line("you signed up for {$appName} a while ago, but your app never sent any data to us. That's totally fine, I'm just curious why.")
            ->line('What stopped you? Just hit reply, one sentence is enough. For example:')
            // An HtmlString keeps the line breaks, plain lines are joined into one paragraph.
            ->line(new HtmlString(implode("\n", [
                '- the setup looked like too much work',
                "- something didn't work during the installation",
                '- you were just looking around',
                '- you went with another tool (which one?)',
            ])))
            ->line("I read every reply myself. If something was broken, I'll help you get it running.");
    }
}
