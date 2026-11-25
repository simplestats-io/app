<?php

namespace App\Notifications\Lifecycle;

use App\Models\Project;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\HtmlString;
use Symfony\Component\Mime\Email;

abstract class LifecycleNotification extends Notification
{
    public function __construct(
        public User $user,
        public Team $team,
    ) {}

    /**
     * Stored per team in `lifecycle_mails`, so renaming a key sends the mail again.
     */
    abstract public static function key(): string;

    /**
     * The teams this mail is due for right now. Teams that already got it are excluded by the sender.
     *
     * @return Builder<Team>
     */
    abstract public static function audience(): Builder;

    abstract protected function compose(MailMessage $mail): MailMessage;

    /**
     * A last check on the single team, for conditions too costly or awkward for the audience query.
     */
    public function shouldSend(): bool
    {
        return true;
    }

    /**
     * Personal mails come from the founder and invite a reply, the others are plain product mails.
     */
    public function isPersonal(): bool
    {
        return false;
    }

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
        $founder = config('mail.founder');
        $optOutUrl = URL::signedRoute('lifecycle-mails.opt-out.create', ['user' => $this->user]);

        $mail = $this->compose((new MailMessage)->greeting("Hi {$this->user->name},"));

        if ($this->isPersonal()) {
            $mail->from(config('mail.from.address'), "{$founder['name']} from {$appName}")
                ->replyTo($founder['address'], $founder['name'])
                ->salutation(new HtmlString("Thanks,<br>\n".e($founder['name'])."<br>\nFounder of ".e($appName)));
        }

        return $mail
            ->markdown('notifications::email', [
                'footnote' => new HtmlString('You get these occasional mails because you signed up for '.e($appName).'. <a href="'.e($optOutUrl).'" style="color: #9ca3af;">Unsubscribe</a>'),
            ])
            ->withSymfonyMessage(function (Email $message) use ($optOutUrl): void {
                $message->getHeaders()->addTextHeader('List-Unsubscribe', "<{$optOutUrl}>");
                $message->getHeaders()->addTextHeader('List-Unsubscribe-Post', 'List-Unsubscribe=One-Click');
            });
    }

    /**
     * Invited users get a personal team on the SaaS too, but they signed up to join
     * another team, not to set up their own. Their invitation may still be pending.
     *
     * @param  Builder<Team>  $query
     * @return Builder<Team>
     */
    protected static function withoutInvitedOwners(Builder $query): Builder
    {
        return $query
            ->whereDoesntHave('owner.teams')
            ->whereDoesntHave('owner', fn (Builder $query): Builder => $query->whereIn('email', TeamInvitation::query()->select('email')));
    }

    /**
     * The project is created unnamed by the team step and only gets its name in
     * the project step, so an unnamed project still means an unfinished setup.
     *
     * @param  Builder<Project>  $query
     * @return Builder<Project>
     */
    protected static function namedProjects(Builder $query): Builder
    {
        return $query->withoutGlobalScopes()->where('name', '<>', '');
    }
}
