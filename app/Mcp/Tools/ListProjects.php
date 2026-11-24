<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\ManagesProjects;
use App\Models\Project;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Gate;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Throwable;

#[Description('List the projects of the current team that you can access, with their project_id (the parameter of the other tools), domain, tracked usages and flags, and whether you may create more projects. Only available when connected as a user (OAuth).')]
#[IsReadOnly]
#[IsIdempotent]
class ListProjects extends Tool
{
    use ManagesProjects;

    public function shouldRegister(): bool
    {
        return $this->signedInUser() !== null;
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        try {
            $user = $this->mcpUser();

            $projects = Project::query()->orderBy('name')->get();

            return Response::structured([
                'team' => $this->mcpTeam($user)->name,
                'projects' => $projects->map($this->projectPayload(...))->all(),
                'can_create_projects' => Gate::forUser($user)->check('create', Project::class),
            ]);
        } catch (AuthorizationException $e) {
            return Response::error($e->getMessage());
        } catch (Throwable $e) {
            report($e);

            return Response::error('Could not list the projects. The error was reported, tell the user to try again.');
        }
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
