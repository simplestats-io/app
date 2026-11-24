<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\ResolvesStatsFilters;
use App\Services\StatsDetailsService;
use App\Services\StatsService;
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

#[Description('Break down stats by a dimension (e.g. track_referer, track_source, track_campaign, location_country, device_platform) for a date range. Answers questions like "which referrer brings the most revenue". Returns the top entries sorted by the given metric.')]
#[IsReadOnly]
class GetGroupedBreakdown extends Tool
{
    use ResolvesStatsFilters;

    private const array SORTS = ['net', 'visitors', 'reg', 'cr', 'dau', 'arpu', 'views', 'vpv', 'bounce_rate'];

    private const array MONEY_KEYS = ['net', 'gross', 'arpu', 'arpv', 'arps', 'arpdau', 'mrr'];

    public function __construct(protected StatsDetailsService $statsDetailsService) {}

    public function handle(Request $request): Response|ResponseFactory
    {
        try {
            $request->validate([
                'dimension' => ['required', Rule::in($this->dimensions())],
                'sort' => ['nullable', Rule::in(self::SORTS)],
                'limit' => ['nullable', 'integer', 'min:1', 'max:25'],
                ...$this->rangeRules(),
            ]);

            $filters = $this->resolveFilters($request);
            $dimension = $request->get('dimension');

            $rows = $this->centsToCurrency($this->statsDetailsService->getGroupedData(
                $filters,
                $dimension,
                (int) ($request->get('limit') ?? 10),
                $request->get('sort') ?? ($dimension === StatsService::TOP_PAGES_TYPE ? 'views' : 'net'),
                withViews: true,
            ), self::MONEY_KEYS);

            return Response::structured([
                'scope' => $this->describeScope($filters),
                'dimension' => $dimension,
                'rows' => $rows,
                'note' => $rows === []
                    ? 'No data for this range/filter.'
                    : 'Monetary values are in the scope currency, per-range. A name of "Direct / Unknown" means no referrer was recorded. If the requested sort is not available for this project, results fall back to sorting by net revenue. views are all pages viewed by the visitors of a row (for page_entry: by visitors who entered there, not views of that page); views of a single page are in the top_pages dimension.',
            ]);
        } catch (ValidationException $e) {
            return Response::error('Invalid arguments: '.collect($e->errors())->flatten()->implode(' '));
        } catch (Throwable $e) {
            report($e);

            return Response::error('Could not load breakdown. The error was reported, tell the user to try again or rephrase.');
        }
    }

    /**
     * @return list<string>
     */
    private function dimensions(): array
    {
        return [...StatsService::getStatsTypesFlat()->all(), StatsService::TOP_PAGES_TYPE];
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'dimension' => $schema->string()
                ->enum($this->dimensions())
                ->required()
                ->description('The dimension to group by. Use list-stats-capabilities to see all dimensions with titles.'),
            'sort' => $schema->string()
                ->enum(self::SORTS)
                ->description('Metric to sort by: net (net revenue, default), visitors, reg (registrations), cr (conversion rate), dau (active users), arpu, views, vpv (views per visitor), bounce_rate. top_pages sorts by views or visitors.'),
            'limit' => $schema->integer()
                ->description('Number of top entries to return (1-25, default 10).'),
            ...$this->rangeSchema($schema),
        ];
    }
}
