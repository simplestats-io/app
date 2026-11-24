<?php

namespace App\Mcp\Tools;

use App\Actions\CreateProject as CreateProjectAction;
use App\Mcp\Tools\Concerns\ManagesProjects;
use App\Models\PersonalAccessToken;
use App\Models\Project;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Throwable;

#[Description('Create a project in the current team and return its API token once (it is needed for the client package, SIMPLESTATS_API_TOKEN in the .env of the tracked app). The token is not returned again by any tool: hand it to the user right away. Subject to the plan\'s project limit. Confirm name, domain and what to track with the user before creating.')]
#[IsReadOnly(false)]
#[IsDestructive(false)]
#[IsIdempotent(false)]
class CreateProject extends Tool
{
    use ManagesProjects;

    public function shouldRegister(): bool
    {
        return $this->signedInUser() !== null && ! $this->isReadOnlyConnection();
    }

    public function handle(Request $request, CreateProjectAction $createProject): Response|ResponseFactory
    {
        try {
            $user = $this->mcpUser();
            $this->ensureWriteAccess();

            if (Gate::forUser($user)->denies('create', Project::class)) {
                throw new AuthorizationException('You cannot create a project: your role or project access does not allow it, or the team reached the project limit of its plan.');
            }

            $project = $createProject($user, $this->validatedProjectInput($request->all()));

            return Response::structured([
                'project' => $this->projectPayload($project),
                'api_token' => $this->plainProjectToken($project),
                'note' => 'Shown only this once. Install the client package (composer require simplestats-io/laravel-client) and set SIMPLESTATS_API_TOKEN to this token. Docs: '.url('/docs/installation.html'),
            ]);
        } catch (ValidationException $e) {
            return Response::error('Invalid arguments: '.collect($e->errors())->flatten()->implode(' '));
        } catch (AuthorizationException $e) {
            return Response::error($e->getMessage());
        } catch (Throwable $e) {
            report($e);

            return Response::error('Could not create the project. The error was reported, tell the user to try again.');
        }
    }

    /**
     * The token the ProjectCreated event just issued, the same value the web UI
     * flashes once after creating a project.
     */
    protected function plainProjectToken(Project $project): ?string
    {
        $token = $project->tokens()->first();

        return $token instanceof PersonalAccessToken ? $token->token_plain_part : null;
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        $properties = $this->projectSchema($schema);

        return [
            ...$properties,
            'name' => $properties['name']->required(),
            'domain' => $properties['domain']->required(),
            'usages' => $properties['usages']->required(),
        ];
    }
}
