<?php

namespace App\Http\Controllers;

use App\Actions\Jetstream\AddTeamMember;
use App\Actions\Jetstream\InviteTeamMember;
use App\Actions\Jetstream\UpdateTeamMember;
use App\Http\Requests\TeamMemberAccessRequest;
use App\Models\Team;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Laravel\Jetstream\Contracts\RemovesTeamMembers;
use Laravel\Jetstream\Features;

/**
 * Replaces Jetstream's vendor routes for {team}: the vendor controllers
 * findOrFail() the raw route parameter as a primary key, which breaks with
 * the public uuid route key. These actions bind the Team implicitly and
 * otherwise mirror the vendor behavior, plus the member's project access.
 */
class TeamMemberController extends Controller
{
    public function store(TeamMemberAccessRequest $request, Team $team): RedirectResponse
    {
        if (Features::sendsTeamInvitations()) {
            resolve(InviteTeamMember::class)->invite(
                $request->user(),
                $team,
                $request->email ?: '',
                $request->role,
                $request->projectIds(),
            );
        } else {
            resolve(AddTeamMember::class)->add(
                $request->user(),
                $team,
                $request->email ?: '',
                $request->role,
                $request->projectIds(),
            );
        }

        return back(303);
    }

    public function update(TeamMemberAccessRequest $request, Team $team, User $user): RedirectResponse
    {
        resolve(UpdateTeamMember::class)->update(
            $request->user(),
            $team,
            $user,
            (string) $request->role,
            $request->projectIds(),
        );

        return back(303);
    }

    public function destroy(Request $request, Team $team, User $user): RedirectResponse
    {
        resolve(RemovesTeamMembers::class)->remove(
            $request->user(),
            $team,
            $user
        );

        if ($request->user()->id === $user->id) {
            return redirect(config('fortify.home'));
        }

        return back(303);
    }
}
