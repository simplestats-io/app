<?php

namespace App\Mcp\Tools;

use App\Actions\UpdateProject as UpdateProjectAction;
use App\Mcp\Tools\Concerns\ManagesProjects;
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

#[Description('Change the name, domain, tracked usages or flags of a project. Omitted fields keep their value. Changing the usages recomputes the cached dashboard numbers of the project. Look up the project_id with list-projects.')]
#[IsReadOnly(false)]
#[IsDestructive]
#[IsIdempotent]
class UpdateProject extends Tool
{
    use ManagesProjects;

    public function shouldRegister(): bool
    {
        return $this->signedInUser() !== null && ! $this->isReadOnlyConnection();
    }

    public function handle(Request $request, UpdateProjectAction $updateProject): Response|ResponseFactory
    {
        try {
            $user = $this->mcpUser();
            $this->ensureWriteAccess();

            $request->validate(['project_id' => ['required', 'integer', 'min:1']]);

            $project = Project::query()->whereKey($request->get('project_id'))->first()
                ?? throw ValidationException::withMessages(['project_id' => 'No accessible project with this project_id exists. Use list-projects to look it up.']);

            if (Gate::forUser($user)->denies('update', $project)) {
                throw new AuthorizationException('Your team role does not allow changing this project.');
            }

            $validated = $this->validatedProjectInput([
                'name' => $request->get('name') ?? $project->name,
                'domain' => $request->get('domain') ?? $project->domain,
                'usages' => $request->get('usages') ?? $this->usageKeys($project),
                'flags' => $request->get('flags') ?? $this->flagKeys($project),
            ]);

            return Response::structured([
                'project' => $this->projectPayload($updateProject($user, $project, $validated)),
            ]);
        } catch (ValidationException $e) {
            return Response::error('Invalid arguments: '.collect($e->errors())->flatten()->implode(' '));
        } catch (AuthorizationException $e) {
            return Response::error($e->getMessage());
        } catch (Throwable $e) {
            report($e);

            return Response::error('Could not update the project. The error was reported, tell the user to try again.');
        }
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'project_id' => $schema->integer()
                ->description('The project_id from list-projects.')
                ->required(),
            ...$this->projectSchema($schema),
        ];
    }
}
