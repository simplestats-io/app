<?php

namespace App\Http\Controllers;

use App\Actions\Jetstream\AddTeamMember;
use App\Models\TeamInvitation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Replaces Jetstream's accept action so the invitation's project restriction
 * reaches the membership (the vendor action only passes the role on). The
 * signed URL stays identical, so already sent invitation mails keep working.
 */
class TeamInvitationController extends Controller
{
    public function accept(Request $request, TeamInvitation $invitation): RedirectResponse
    {
        // billingOwner instead of owner: a soft-deleted owner keeps the team alive
        // during the grace window, and the plain relation is null then.
        resolve(AddTeamMember::class)->add(
            $invitation->team->billingOwner,
            $invitation->team,
            $invitation->email,
            $invitation->role,
            $invitation->project_ids,
        );

        $invitation->delete();

        return redirect(config('fortify.home'))->banner(
            __('Great! You have accepted the invitation to join the :team team.', ['team' => $invitation->team->name]),
        );
    }
}
