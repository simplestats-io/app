<?php

namespace App\Http\Controllers;

use App\Actions\CheckPasswordConfirmation;
use App\Actions\CreateProject;
use App\Actions\UpdateProject;
use App\Http\Requests\ProjectRequest;
use App\Models\GscConnection;
use App\Models\Project;
use App\Services\Gsc\GscConnectionService;
use App\Services\StatsAggregationsService;
use Closure;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class ProjectController extends Controller
{
    public function __construct()
    {
        $this->authorizeResource(Project::class, 'project');
    }

    public function index(Request $request): Response
    {
        return Inertia::render('Projects/Index', [
            'projects' => Project::orderBy('created_at', 'desc')->paginate(10),
            'usages' => Project::getUsages(),
            'flags' => Project::getFlags(),
            'permissions' => [
                'canCreateProject' => Gate::check('create', Project::class),
            ],
        ]);
    }

    public function store(ProjectRequest $request): RedirectResponse
    {
        $project = resolve(CreateProject::class)($request->user(), $request->validated());

        $token = $project->tokens()->first();

        return to_route('projects.show', $project)
            ->banner('Project created: '.$project->name)
            ->with('flash', [
                'token' => $token->token_plain_part,
            ]);
    }

    public function show(Request $request, Project $project, GscConnectionService $connections): Response
    {
        if (Gate::check('viewProjectToken', $project)) {
            $project->load('tokens');
        }

        $gscConnection = $project->gscConnection;
        $canUpdate = Gate::check('update', $project);
        $connectedByUser = $gscConnection?->user()->is($request->user()) ?? false;

        return Inertia::render('Projects/Show', [
            'project' => $project,
            'searchConsole' => [
                'available' => GscConnection::isAvailable(),
                'connection' => $gscConnection ? [
                    ...$gscConnection->only(['site_url', 'last_synced_at', 'reconnect_required_at']),
                    'google_email' => $canUpdate ? $gscConnection->google_email : null,
                    'connected_by_user' => $connectedByUser,
                ] : null,
                'reusable_accounts' => $canUpdate && ! $gscConnection ? $connections->reusableAccounts($request->user(), $project) : [],
            ],
            'gscSites' => Inertia::defer(fn (): array => $connectedByUser && $canUpdate && $gscConnection->needsSiteSelection()
                ? $connections->sitesWithSuggestion($gscConnection, $project)
                : ['sites' => [], 'suggested' => null, 'error' => null]),
            'usages' => Project::getUsages(),
            'flags' => Project::getFlags(),
            'permissions' => [
                'canDeleteProject' => Gate::check('delete', $project),
                'canUpdateProject' => Gate::check('update', $project),
                'canCreateProject' => Gate::check('create', $project),
                'canRegenerateProjectToken' => Gate::check('regenerateProjectToken', $project),
            ],
        ]);
    }

    public function update(ProjectRequest $request, Project $project): RedirectResponse
    {
        resolve(UpdateProject::class)($request->user(), $project, $request->validated());

        return back();
    }

    public function updateUserLinkUrl(Request $request, Project $project): RedirectResponse
    {
        $this->authorize('update', $project);

        $request->merge([
            'user_link_base_url' => filled($request->user_link_base_url)
                ? trim($request->user_link_base_url)
                : null,
        ]);

        $validated = $request->validate([
            'user_link_base_url' => ['nullable', 'string', 'max:255', function (string $attribute, mixed $value, Closure $fail): void {
                // {id} is a placeholder for the tracked user id and is not a valid URL
                // character, so validate the URL with a stand-in in its place.
                if (! filter_var(str_replace('{id}', '1', $value), FILTER_VALIDATE_URL)) {
                    $fail('The user link base URL must be a valid URL.');
                }
            }],
        ]);

        $url = $validated['user_link_base_url'] ?? null;

        // Only trim a trailing slash in append mode; a token url is stored verbatim so
        // the {id} position is preserved exactly.
        if ($url !== null && ! str_contains($url, '{id}')) {
            $url = rtrim($url, '/');
        }

        $project->update(['user_link_base_url' => $url]);

        return back();
    }

    public function destroy(Request $request, Project $project, StatefulGuard $guard): RedirectResponse
    {
        resolve(CheckPasswordConfirmation::class)($guard, $request->user(), $request->password);

        resolve(StatsAggregationsService::class)->clearByProject($project);

        $project->delete();

        return to_route('projects.index')->banner('Project deleted: '.$project->name);
    }

    public function tokenRegenerate(Request $request, Project $project, StatefulGuard $guard)
    {
        $this->authorize('regenerateProjectToken', $project);

        resolve(CheckPasswordConfirmation::class)($guard, $request->user(), $request->password);

        $project->tokens()->delete();

        $token = $project->createTokenPlain(Project::TOKEN_NAME);

        return back()->with('flash', [
            'token' => $token->accessToken->token_plain_part,
            'banner' => 'Token regenerated!',
        ]);
    }

    public function tokenView(Project $project)
    {
        $this->authorize('viewProjectToken', $project);

        $token = $project->tokens()->first();

        return to_route('projects.show', [$project])->with('flash', [
            'token' => $token->token_plain_part,
        ]);
    }
}
