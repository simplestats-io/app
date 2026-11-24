<?php

namespace App\Mcp\Tools\Concerns;

use App\Http\Requests\ProjectRequest;
use App\Models\Project;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

trait ManagesProjects
{
    use ResolvesMcpUser;

    /**
     * Validates through ProjectRequest, so the tools get the same domain
     * normalization, forced visitors bit and Registrations + Payments rules as the
     * web form. Usages and flags travel as their keys instead of bitmasks.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    protected function validatedProjectInput(array $input): array
    {
        Validator::make($input, [
            'usages' => ['required', 'array', 'min:1'],
            'usages.*' => ['string', 'distinct', Rule::in(Project::getUsages()->keys())],
            'flags' => ['nullable', 'array'],
            'flags.*' => ['string', 'distinct', Rule::in(Project::getFlags()->keys())],
        ])->validate();

        $form = ProjectRequest::create('', 'POST', [
            'name' => $input['name'] ?? null,
            'domain' => $input['domain'] ?? null,
            'usage' => $this->bitmask(Project::getUsages()->only($input['usages'])->all()),
            'flags' => $this->bitmask(Project::getFlags()->only($input['flags'] ?? [])->all()),
        ]);
        $form->setContainer(app())->setRedirector(resolve('redirect'));
        $form->validateResolved();

        return $form->validated();
    }

    /**
     * @param  array<string, array{bit: int}>  $definitions
     */
    protected function bitmask(array $definitions): int
    {
        return collect($definitions)->sum('bit');
    }

    /**
     * @return array<int, string>
     */
    protected function usageKeys(Project $project): array
    {
        return Project::getUsages()->filter(fn (array $usage): bool => $project->hasUsages([$usage['bit']]))->keys()->all();
    }

    /**
     * @return array<int, string>
     */
    protected function flagKeys(Project $project): array
    {
        return Project::getFlags()->filter(fn (array $flag): bool => $project->hasFlags([$flag['bit']]))->keys()->all();
    }

    /**
     * @return array<string, mixed>
     */
    protected function projectPayload(Project $project): array
    {
        return [
            'project_id' => $project->id,
            'name' => $project->name,
            'domain' => $project->domain,
            'usages' => $this->usageKeys($project),
            'flags' => $this->flagKeys($project),
            'created_at' => $project->created_at?->toDateString(),
        ];
    }

    /**
     * @return array<string, Type>
     */
    protected function projectSchema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()
                ->description('Display name of the project, at most 30 characters.'),
            'domain' => $schema->string()
                ->description('Domain of the tracked app, e.g. "example.com". A URL is reduced to its host.'),
            'usages' => $schema->array()
                ->items($schema->string()->enum(Project::getUsages()->keys()->all()))
                ->description('What the project tracks: '.Project::getUsages()->map(fn (array $usage, string $key): string => "{$key} ({$usage['descr']})")->implode(' ').' Visitors are always tracked. Subscriptions require users and payments.'),
            'flags' => $schema->array()
                ->items($schema->string()->enum(Project::getFlags()->keys()->all()))
                ->description('Business model properties: '.Project::getFlags()->map(fn (array $flag, string $key): string => "{$key} ({$flag['descr']})")->implode(' ').' Requires users and payments. An empty list clears them.'),
        ];
    }
}
