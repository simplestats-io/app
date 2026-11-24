<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\ResolvesStatsFilters;
use App\Models\Project;
use App\Services\StatsDetailsService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Throwable;

#[Description('Get recurring revenue (subscription) stats for a date range. Pick one section per call: snapshot (MRR/ARR now vs range start), by_plan (MRR + subscribers per plan), time_series (MRR + subscribers per bucket), movements (new/expansion/contraction/churn MRR per bucket), mrr_retention (NRR/GRR/quick ratio over the range), revenue_churn (churn rates per bucket), economics (ARPS, monthly churn, LTV). Only meaningful for projects that track subscriptions.')]
#[IsReadOnly]
class GetRecurringRevenueStats extends Tool
{
    use ResolvesStatsFilters;

    /**
     * @var array<string, array{method: string, money: list<string>, scalar: bool}>
     */
    private const array SECTIONS = [
        'snapshot' => ['method' => 'getMrrSnapshot', 'money' => ['mrr', 'arr', 'mrr_prev'], 'scalar' => true],
        'by_plan' => ['method' => 'getMrrByPlan', 'money' => ['mrr'], 'scalar' => false],
        'time_series' => ['method' => 'getMrrTimeSeries', 'money' => ['mrr'], 'scalar' => false],
        'movements' => ['method' => 'getMrrMovements', 'money' => ['starting_mrr', 'new', 'reactivation', 'expansion', 'contraction', 'churn', 'ending_mrr', 'net_change'], 'scalar' => false],
        'mrr_retention' => ['method' => 'getMrrRetention', 'money' => ['starting_mrr', 'ending_mrr', 'new', 'reactivation', 'expansion', 'contraction', 'churn'], 'scalar' => true],
        'revenue_churn' => ['method' => 'getRevenueChurn', 'money' => ['mrr'], 'scalar' => false],
        'economics' => ['method' => 'getSubscriptionEconomics', 'money' => ['arps', 'ltv'], 'scalar' => true],
    ];

    private const array SECTION_NOTES = [
        'snapshot' => 'mrr is the point-in-time MRR at range end, mrr_prev at range start, arr = mrr * 12.',
        'by_plan' => 'MRR and subscriber count per subscription plan at range end.',
        'time_series' => 'Rows are ordered newest first.',
        'movements' => 'Rows are ordered newest first. Needs a range of at least 2 buckets, otherwise empty.',
        'mrr_retention' => 'nrr/grr are percentages, quick_ratio is a ratio. Needs a range of at least 2 buckets.',
        'revenue_churn' => 'Rows are ordered newest first. gross_churn_rate/net_churn_rate are percentages.',
        'economics' => 'monthly_churn is a fraction (0.05 = 5%). Null when there are no subscribers in the range.',
    ];

    public function __construct(protected StatsDetailsService $statsDetailsService) {}

    public function handle(Request $request): Response|ResponseFactory
    {
        try {
            $request->validate([
                ...$this->rangeRules(),
                'section' => ['required', Rule::in(array_keys(self::SECTIONS))],
            ]);

            $filters = $this->resolveFilters($request);

            if (! $this->tracksUsage($filters, Project::USAGE_SUBSCRIPTION)) {
                return Response::structured([
                    'scope' => $this->describeScope($filters),
                    'note' => 'This project does not track subscriptions, so recurring revenue metrics are not available. Suggest checking the project settings or asking about one-time revenue via the KPI stats instead.',
                ]);
            }

            $section = $request->get('section');
            $config = self::SECTIONS[$section];

            $data = $this->statsDetailsService->{$config['method']}($filters);

            if ($data !== null) {
                $data = $config['scalar']
                    ? $this->centsToCurrency([$data], $config['money'])[0]
                    : $this->centsToCurrency($data, $config['money']);
            }

            $perProject = $section === 'snapshot'
                ? $this->perProjectBreakdown(
                    $filters,
                    fn (int $projectId): array => $this->centsToCurrency(
                        [$this->statsDetailsService->getMrrSnapshot($this->resolveFilters($request, $projectId))],
                        self::SECTIONS['snapshot']['money'],
                    )[0],
                    Project::USAGE_SUBSCRIPTION,
                )
                : null;

            return Response::structured(array_filter([
                'scope' => $this->describeScope($filters),
                'section' => $section,
                'data' => $data,
                'per_project' => $perProject,
                'note' => ($data === null || $data === []
                    ? 'No subscription data for this range/filter. '
                    : '').self::SECTION_NOTES[$section].' Monetary values are in the scope currency.',
            ], fn ($value): bool => $value !== null));
        } catch (ValidationException $e) {
            return Response::error('Invalid arguments: '.collect($e->errors())->flatten()->implode(' '));
        } catch (Throwable $e) {
            report($e);

            return Response::error('Could not load recurring revenue stats. The error was reported, tell the user to try again or rephrase.');
        }
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'section' => $schema->string()
                ->enum(array_keys(self::SECTIONS))
                ->description('Which recurring revenue section to load. One section per call.'),
            ...$this->rangeSchema($schema),
        ];
    }
}
