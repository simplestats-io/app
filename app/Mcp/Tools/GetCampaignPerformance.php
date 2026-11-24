<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\ResolvesStatsFilters;
use App\Models\Project;
use App\Services\AdSpendService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Throwable;

#[Description('Get per-campaign profitability for a date range: visitors, registrations, revenue, ad spend, net profit, ROAS, CAC, LTV:CAC and CAC payback. Use this to compare campaigns ("which campaign performs best?"), to explain campaign metrics, or for ad spend / ROI questions. Only meaningful for projects that track payments.')]
#[IsReadOnly]
class GetCampaignPerformance extends Tool
{
    use ResolvesStatsFilters;

    private const array MONEY_KEYS = ['net', 'spend', 'spend_total', 'net_profit', 'cac', 'cost_per_visitor'];

    /**
     * Mirrors campaignMetricInfo in resources/js/Data/CampaignMetricInfo.js,
     * so the assistant explains the metrics the same way the UI tooltips do.
     */
    private const array LEGEND = [
        'visitors' => 'Unique visitors the campaign brought in during the range',
        'reg' => 'New registrations attributed to the campaign during the range',
        'net' => 'Net revenue (after fees) to date from the customers this campaign acquired during the range, including payments made after the range',
        'spend' => 'The share of the ad spend that falls into the range: each entry (entered manually by the user) is spread evenly across its days',
        'spend_total' => 'Sum of all ad spend entries of the campaign, independent of the range',
        'cost_per_visitor' => 'Ad spend / visitors in the range. Null without spend or visitors',
        'net_profit' => 'Revenue minus ad spend; positive means the campaign earned more than it cost',
        'roas' => 'Return on ad spend: revenue / spend. 2.0 means 2 earned per 1 spent; above 1.0 is profitable. Recently acquired customers have had little time to pay yet',
        'cac' => 'Customer acquisition cost: spend / registrations, the average cost of one new sign-up. Null without spend in the range',
        'runs_from' => 'First day covered by the campaign\'s ad spend entries, independent of the range',
        'runs_until' => 'Last day covered by the campaign\'s ad spend entries, independent of the range',
        'has_activity' => 'False when the campaign had no spend, registrations or revenue for the range',
        'ltv_cac' => 'Subscriber lifetime value / CAC. 3.0 or higher is healthy, below 1.0 loses money per customer. Null when the project does not track subscriptions or has no data',
        'cac_payback' => 'Months until a customer earns back their acquisition cost through recurring revenue. Under 12 is healthy, over 24 ties up cash. Null without subscription data',
    ];

    public function __construct(protected AdSpendService $adSpendService) {}

    public function handle(Request $request): Response|ResponseFactory
    {
        try {
            $request->validate([
                ...$this->rangeRules(),
                'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
            ]);

            $filters = $this->resolveFilters($request);

            if (! $this->tracksUsage($filters, Project::USAGE_PAYMENTS)) {
                return Response::structured([
                    'scope' => $this->describeScope($filters),
                    'note' => 'This project does not track payments, so campaign profitability is not available.',
                ]);
            }

            $rows = collect($this->adSpendService->getCampaignProfitability($filters, (int) ($request->get('limit') ?? 20))->items())
                ->sortByDesc('net_profit')
                ->values()
                ->all();

            return Response::structured([
                'scope' => $this->describeScope($filters),
                'campaigns' => $this->centsToCurrency($rows, self::MONEY_KEYS),
                'legend' => self::LEGEND,
                'note' => $rows === []
                    ? 'No campaigns exist yet. Campaigns are created on the Campaigns page and bundle one or more tracked utm_campaign values plus manually entered ad spend.'
                    : 'Campaigns are sorted by net_profit (best first). Monetary values are in the scope currency; roas and ltv_cac are ratios, cac_payback is in months. Spend only appears when the user entered ad spend for the range.',
            ]);
        } catch (ValidationException $e) {
            return Response::error('Invalid arguments: '.collect($e->errors())->flatten()->implode(' '));
        } catch (Throwable $e) {
            report($e);

            return Response::error('Could not load campaign performance. The error was reported, tell the user to try again or rephrase.');
        }
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            ...$this->rangeSchema($schema),
            'limit' => $schema->integer()
                ->description('Maximum number of campaigns to return (default 20, max 50).'),
        ];
    }
}
