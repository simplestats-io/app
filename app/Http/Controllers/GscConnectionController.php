<?php

namespace App\Http\Controllers;

use App\Http\Requests\GscConnectionRequest;
use App\Models\GscConnection;
use App\Models\Project;
use App\Services\Gsc\GscApiService;
use App\Services\Gsc\GscConnectionService;
use GuzzleHttp\Exception\ClientException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Laravel\Socialite\Two\InvalidStateException;
use Laravel\Socialite\Two\User as GoogleUser;
use Symfony\Component\HttpFoundation\RedirectResponse as SymfonyRedirectResponse;

class GscConnectionController extends Controller
{
    public const string SESSION_KEY = 'gsc.project';

    public function create(Request $request, Project $project, GscConnectionService $connections): SymfonyRedirectResponse
    {
        $this->authorize('update', $project);
        abort_unless(GscConnection::isAvailable(), 404);

        $request->session()->put(self::SESSION_KEY, $project->uuid);

        return $connections->googleDriver()
            ->scopes([GscApiService::SCOPE])
            ->with(['access_type' => 'offline', 'prompt' => 'consent', 'include_granted_scopes' => 'true'])
            ->redirect();
    }

    /**
     * OAuth callback. Google only redirects to a fixed url, so the project comes from the session.
     */
    public function store(Request $request, GscConnectionService $connections): RedirectResponse
    {
        abort_unless(GscConnection::isAvailable(), 404);

        $project = Project::where('uuid', $request->session()->pull(self::SESSION_KEY))->firstOrFail();
        $this->authorize('update', $project);

        if ($request->filled('error')) {
            return to_route('projects.show', $project)
                ->dangerBanner('Google Search Console was not connected.');
        }

        try {
            $googleUser = $connections->googleDriver()->user();
        } catch (InvalidStateException) {
            return to_route('projects.show', $project)
                ->dangerBanner('The Google sign-in expired. Please try again.');
        } catch (ClientException $exception) {
            report($exception);

            return to_route('projects.show', $project)
                ->dangerBanner('Google rejected the sign-in. Please try again.');
        }

        if (! $googleUser instanceof GoogleUser || ! in_array(GscApiService::SCOPE, $googleUser->approvedScopes, true)) {
            return to_route('projects.show', $project)
                ->dangerBanner('Please allow access to your Search Console data to connect it.');
        }

        $connection = $connections->connect($project, $request->user(), $googleUser);

        return to_route('projects.show', $project)
            ->banner($connection->site_url !== null
                ? "Google Search Console connected with {$connection->site_url}. Keywords are being imported."
                : 'Google Search Console connected. Pick the property of this project.');
    }

    public function storeFromExisting(Request $request, Project $project, GscConnectionService $connections): RedirectResponse
    {
        $this->authorize('update', $project);
        abort_unless(GscConnection::isAvailable(), 404);

        $validated = $request->validate(['google_email' => ['required', 'string', 'max:255']]);

        $connection = $connections->connectWithExistingAccount($project, $request->user(), $validated['google_email']);

        abort_if($connection === null, 404);

        return back()->banner($connection->site_url !== null
            ? "Google Search Console connected with {$connection->site_url}. Keywords are being imported."
            : 'Google Search Console connected. Pick the property of this project.');
    }

    public function update(GscConnectionRequest $request, Project $project, GscConnectionService $connections): RedirectResponse
    {
        $connections->selectSite($request->connection(), $request->validated('site_url'));

        return back()->banner('Search Console property linked. Up to 16 months of keywords are being imported.');
    }

    public function destroy(Project $project, GscConnectionService $connections): RedirectResponse
    {
        $this->authorize('update', $project);

        $connections->disconnect($project->gscConnection()->firstOrFail());

        return back()->banner('Google Search Console disconnected.');
    }
}
