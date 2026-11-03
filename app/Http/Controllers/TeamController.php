<?php

namespace App\Http\Controllers;

use App\Http\DataProviders\TeamShowDataProvider;
use App\Models\Team;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Response;
use Laravel\Jetstream\Actions\ValidateTeamDeletion;
use Laravel\Jetstream\Contracts\DeletesTeams;
use Laravel\Jetstream\Contracts\UpdatesTeamNames;
use Laravel\Jetstream\Jetstream;
use Laravel\Jetstream\RedirectsActions;

/**
 * Replaces Jetstream's vendor routes for {team}: the vendor controllers
 * findOrFail() the raw route parameter as a primary key, which breaks with
 * the public uuid route key. These actions bind the Team implicitly and
 * otherwise mirror the vendor behavior.
 */
class TeamController extends Controller
{
    use RedirectsActions;

    public function show(Request $request, Team $team, TeamShowDataProvider $dataProvider): Response
    {
        Gate::authorize('view', $team);

        return Jetstream::inertia()->render($request, 'Teams/Show', $dataProvider->toArray());
    }

    public function update(Request $request, Team $team): RedirectResponse
    {
        resolve(UpdatesTeamNames::class)->update($request->user(), $team, $request->all());

        return back(303);
    }

    public function destroy(Request $request, Team $team): RedirectResponse
    {
        resolve(ValidateTeamDeletion::class)->validate($request->user(), $team);

        $deleter = resolve(DeletesTeams::class);

        $deleter->delete($team);

        return $this->redirectPath($deleter);
    }
}
