<?php

namespace App\Http\Middleware;

use App\Providers\RouteServiceProvider;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class ForwardToOnboarding
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $currentTeam = $request->user()?->currentTeam;

        // Members limited to selected projects can't create projects, so the
        // project setup would be a dead end once all their projects are gone.
        if ($currentTeam && $request->user()->hasRestrictedProjectAccess($currentTeam)) {
            return $next($request);
        }

        if (Str::of($request->getRequestUri())->contains(RouteServiceProvider::HOME)) {
            // we use the currency as the indicator that team onboarding is not finished
            if (! $currentTeam->currency) {
                return to_route('setup.team.index');
            }
            // we use the currency as the indicator that team onboarding is not finished
            if (! $currentTeam->projects()->first()?->name) {
                return to_route('setup.project.index');
            }
        }

        return $next($request);
    }
}
