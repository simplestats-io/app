<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\ResolvesStatsFilters;
use App\Models\Project;
use App\Services\StatsDetailsService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Throwable;

#[Description('Get registration retention cohorts for a date range: for each cohort of users registered in a bucket, the percentage that came back (logged in) in each following bucket. Only meaningful for projects that track registrations.')]
#[IsReadOnly]
class GetRetentionCohorts extends Tool
{
    use ResolvesStatsFilters;

    public function __construct(protected StatsDetailsService $statsDetailsService) {}

    public function handle(Request $request): Response|ResponseFactory
    {
        try {
            $request->validate($this->rangeRules());

            $filters = $this->resolveFilters($request);

            if (! $this->tracksUsage($filters, Project::USAGE_USERS)) {
                return Response::structured([
                    'scope' => $this->describeScope($filters),
                    'note' => 'This project does not track registrations, so retention cohorts are not available.',
                ]);
            }

            $data = $this->statsDetailsService->getRetentionCohorts($filters);

            return Response::structured([
                'scope' => $this->describeScope($filters),
                'data' => $data,
                'note' => $data['cohorts'] === []
                    ? 'No registrations in this range, so there are no cohorts.'
                    : 'Cohorts are ordered oldest first. retention[0] is always the 100% baseline (the registration bucket itself), retention[n] is the % of the cohort active in the n-th following bucket, null means that bucket is still in the future. size is the number of registrations in the cohort.',
            ]);
        } catch (ValidationException $e) {
            return Response::error('Invalid arguments: '.collect($e->errors())->flatten()->implode(' '));
        } catch (Throwable $e) {
            report($e);

            return Response::error('Could not load retention cohorts. The error was reported, tell the user to try again or rephrase.');
        }
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return $this->rangeSchema($schema);
    }
}
