<?php

namespace App\Mail;

use App\Models\Project;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\URL;
use Laravel\Jetstream\TeamInvitation as TeamInvitationModel;

class TeamInvitation extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * The team invitation instance.
     *
     * @var TeamInvitationModel
     */
    public $invitation;

    /**
     * Create a new message instance.
     */
    public function __construct(TeamInvitationModel $invitation)
    {
        $this->invitation = $invitation;
    }

    /**
     * Build the message.
     *
     * @return $this
     */
    public function build()
    {
        $invitedUserAlreadyHasAccount = (bool) User::where('email', $this->invitation->email)->first();

        $data = [
            'invitedUserAlreadyHasAccount' => $invitedUserAlreadyHasAccount,
            'acceptUrl' => URL::signedRoute('team-invitations.accept', ['invitation' => $this->invitation]),
            'projectNames' => $this->projectNames(),
        ];

        return $this->markdown('emails.team-invitation', $data)->subject(__('Team Invitation'));
    }

    /**
     * @return array<int, string>|null null when the invitation grants access to all projects
     */
    private function projectNames(): ?array
    {
        if ($this->invitation->project_ids === null) {
            return null;
        }

        // Rendered in the queue without a team context, so the scopes must not apply.
        return Project::query()
            ->withoutGlobalScopes()
            ->whereBelongsTo($this->invitation->team)
            ->whereKey($this->invitation->project_ids)
            ->orderBy('name')
            ->pluck('name')
            ->all();
    }
}
