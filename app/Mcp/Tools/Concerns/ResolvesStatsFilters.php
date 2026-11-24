<?php

namespace App\Mcp\Tools\Concerns;

use App\DTOs\StatsFilterDto;
use App\Enums\StatsRangePreset;
use App\Models\Project;
use App\Services\Ai\AiStatsFilterFactory;
use Closure;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;

trait ResolvesStatsFilters
{
    /**
     * @return array<string, JsonSchema>
     */
    protected function rangeSchema(JsonSchema $schema): array
    {
        return [
            'preset' => $schema->string()
                ->enum(collect(StatsRangePreset::cases())->reject(fn (StatsRangePreset $preset): bool => $preset === StatsRangePreset::CUSTOM)->pluck('value')->all())
                ->description('Named date range preset. Prefer this for relative ranges like "last month". Either preset or start_date/end_date must be given.'),
            'start_date' => $schema->string()
                ->description('Range start date (YYYY-MM-DD), only when no preset fits.'),
            'end_date' => $schema->string()
                ->description('Range end date (YYYY-MM-DD), required with start_date.'),
            'project_id' => $schema->integer()
                ->description('Project ID to scope to. Use 0 or omit it for all projects.'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function rangeRules(): array
    {
        return [
            'preset' => ['required_without:start_date', 'nullable', Rule::enum(StatsRangePreset::class)->except([StatsRangePreset::CUSTOM])],
            'start_date' => ['required_without:preset', 'nullable', 'date_format:Y-m-d'],
            'end_date' => ['required_with:start_date', 'nullable', 'date_format:Y-m-d', 'after_or_equal:start_date'],
            'project_id' => ['nullable', 'integer', 'min:0'],
        ];
    }

    protected function resolveFilters(Request $request, ?int $overrideProjectId = null): StatsFilterDto
    {
        $requestedProjectId = $overrideProjectId ?? $request->get('project_id');

        return resolve(AiStatsFilterFactory::class)->make(
            preset: $request->get('preset'),
            startDate: $request->get('start_date'),
            endDate: $request->get('end_date'),
            projectId: (int) ($requestedProjectId ?? 0),
        );
    }

    /**
     * @return array{project: string, range: string, currency: string}
     */
    protected function describeScope(StatsFilterDto $filters): array
    {
        return [
            'project' => $filters->projectId === StatsFilterDto::TRACK_ALL
                ? 'All projects'
                : (Project::find($filters->projectId)?->name ?? "Project {$filters->projectId}"),
            'range' => sprintf(
                '%d %s back from %s (%s)',
                $filters->rangeAmount,
                $filters->rangeType->value,
                $filters->rangeStart->format('Y-m-d'),
                config('app.timezone'),
            ),
            'currency' => auth()->user()?->team?->currency ?? 'EUR',
        ];
    }

    /**
     * Per-project variant of a tool result when the scope is "all projects",
     * so the model can break an answer down without one tool call per project.
     * Skipped for single-project teams and for unusually large teams to bound
     * the query cost. $resolve receives a project id and returns the extra
     * keys merged next to the project name.
     *
     * @param  Closure(int): array<string, mixed>  $resolve
     * @return array<int, array<string, mixed>>|null
     */
    protected function perProjectBreakdown(StatsFilterDto $filters, Closure $resolve, ?int $usageBit = null): ?array
    {
        if ($filters->projectId !== StatsFilterDto::TRACK_ALL) {
            return null;
        }

        $projects = Project::query()
            ->when($usageBit !== null, fn ($query) => $query->whereRaw('(usage & ?) > 0', [$usageBit]))
            ->orderBy('id')
            ->get(['id', 'name']);

        if ($projects->count() < 2 || $projects->count() > 10) {
            return null;
        }

        return $projects
            ->map(fn (Project $project): array => ['project' => $project->name, ...$resolve($project->id)])
            ->all();
    }

    /**
     * Whether the filtered project (or, for "all projects", any team project)
     * has the given USAGE_* bit enabled. Team scoping comes from HasTeamScope.
     */
    protected function tracksUsage(StatsFilterDto $filters, int $usageBit): bool
    {
        return Project::query()
            ->when($filters->projectId !== StatsFilterDto::TRACK_ALL, fn ($query) => $query->whereKey($filters->projectId))
            ->whereRaw('(usage & ?) > 0', [$usageBit])
            ->exists();
    }

    /**
     * The stats pipeline stores and returns all monetary values in cents
     * (mirrors cent2Currency() in NumberHelpers.js). Convert before handing
     * numbers to the model, so it never reasons in cents.
     *
     * @param  array<array-key, array<string, mixed>>  $rows
     * @param  list<string>  $moneyKeys
     * @return array<array-key, array<string, mixed>>
     */
    protected function centsToCurrency(array $rows, array $moneyKeys): array
    {
        foreach ($rows as &$row) {
            foreach ($moneyKeys as $key) {
                if (isset($row[$key]) && is_numeric($row[$key])) {
                    $row[$key] = round($row[$key] / 100, 2);
                }
            }
        }
        unset($row);

        return $rows;
    }
}
