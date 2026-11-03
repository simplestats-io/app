<?php

namespace App\Actions\Fortify;

use App\Http\Controllers\TeamInvitationController;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use App\Rules\SignedUrl;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Laravel\Fortify\Contracts\CreatesNewUsers;
use Laravel\Jetstream\Jetstream;

class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules;

    /**
     * Create a newly registered user.
     *
     * @param  array<string, string>  $input
     */
    public function create(array $input): User
    {
        $invitationRule = (config('app.app_type') === 'saas' || app()->runningInConsole()) ? 'nullable' : 'required';

        $rules = [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users'],
            'password' => $this->passwordRules(),
            'terms' => Jetstream::hasTermsAndPrivacyPolicyFeature() ? ['accepted', 'required'] : '',
            'accept_invitation_link' => [$invitationRule, new SignedUrl],
        ];

        if (isset($input['sso']) && $input['sso']) {
            unset($rules['password']);
            $input['terms'] = true;
        }

        if (session()->has('invitation')) {
            $input['accept_invitation_link'] = session('invitation.acceptInvitationLink') ?? null;
        }

        Validator::make($input, $rules)->validate();

        $invitation = false;
        $invitationRequest = false;

        // before creating the new user, validate his invitation if one is set...
        if (! empty($input['accept_invitation_link'])) {
            $invitationRequest = Request::create($input['accept_invitation_link']);
            $id = explode('/', $invitationRequest->getPathInfo())[2];
            $invitation = TeamInvitation::findOrFail($id);

            Validator::make(array_merge($input, ['invitation_mail' => $invitation->email]), [
                'email' => ['same:invitation_mail'],
            ])->validate();
        }

        return DB::transaction(function () use ($input, $invitationRequest, $invitation) {
            $createUser = [
                'name' => $input['name'],
                'email' => $input['email'],
                'settings' => $input['settings'] ?? ['placeholder' => 1],
            ];

            if (isset($input['password'])) {
                $createUser['password'] = Hash::make($input['password']);
            }

            return tap(User::create($createUser), function (User $user) use ($invitationRequest, $invitation): void {
                // In self-hosted, invited users only get the invited team (no personal team).
                // SaaS users always get a personal team. Self-hosted initial admin (no invitation)
                // also gets a team so they have something to put data in.
                $hasInvitation = $invitationRequest && $invitation;
                if (config('app.app_type') === 'saas' || ! $hasInvitation) {
                    $this->createTeam($user);
                }

                if ($hasInvitation) {
                    $this->acceptTeamInvitation($invitationRequest, $invitation, $user);
                }
            });
        });
    }

    /**
     * Accepts a team invitation
     */
    protected function acceptTeamInvitation(Request $request, TeamInvitation $invitation, User $user): void
    {
        $teamInvitation = resolve(TeamInvitationController::class);
        $teamInvitation->accept($request, $invitation);
    }

    /**
     * Create a personal team for the user.
     */
    protected function createTeam(User $user): void
    {
        $user->ownedTeams()->save(Team::forceCreate([
            'user_id' => $user->id,
            'name' => explode(' ', $user->name, 2)[0]."'s Team",
            'personal_team' => true,
        ]));
    }
}
