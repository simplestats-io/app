<?php

namespace App\Mcp\Tools;

use App\DTOs\StatsFilterDto;
use App\Enums\StatsRangePreset;
use App\Models\GscConnection;
use App\Models\Project;
use App\Services\StatsService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Throwable;

#[Description('List the projects you can access, the available breakdown dimensions, date range presets, and a legend of all KPI keys. Call this first when unsure which project, dimension, or preset to use.')]
#[IsReadOnly]
class ListStatsCapabilities extends Tool
{
    public function handle(Request $request): Response|ResponseFactory
    {
        try {
            return Response::structured([
                'projects' => $this->projects(),
                'dimensions' => StatsService::getStatsTypesFlatMap(['model', 'icon'])
                    ->map(fn (array $type): array => ['dimension' => $type['type'], 'title' => $type['title']])
                    ->values()
                    ->push(['dimension' => StatsService::TOP_PAGES_TYPE, 'title' => 'Top Pages (only when the project tracks_page_views)'])
                    ->push(['dimension' => StatsService::KEYWORDS_TYPE, 'title' => 'Google search keywords (only when the project has search_console_connected)'])
                    ->all(),
                'presets' => collect(StatsRangePreset::cases())
                    ->reject(fn (StatsRangePreset $preset): bool => $preset === StatsRangePreset::CUSTOM)
                    ->map(fn (StatsRangePreset $preset): array => ['preset' => $preset->value, 'title' => $preset->title()])
                    ->values()
                    ->all(),
                'kpis' => [
                    'pd_visitor' => 'Unique visitors in the bucket',
                    'pd_view' => 'Page views of the visitors who arrived in the bucket (only when the project tracks_page_views)',
                    'pd_vpv' => 'Page views per visitor with at least one tracked view',
                    'pd_bounce_rate' => 'Share in % of visitors with tracked views who viewed exactly one page that day',
                    'pd_reg' => 'New registrations in the bucket',
                    'pd_cr' => 'Visitor-to-registration conversion rate in % for the bucket',
                    'pd_net' => 'Net revenue in the bucket',
                    'pd_gross' => 'Gross revenue in the bucket',
                    'pd_fee' => 'Fees (gross minus net) in the bucket',
                    'pd_dau' => 'Active users in the bucket',
                    'pd_arpdau' => 'Average net revenue per active user in the bucket',
                    'lt_visitor' => 'Lifetime unique visitors up to the bucket',
                    'lt_reg' => 'Lifetime registrations up to the bucket',
                    'lt_pu' => 'Lifetime paying users up to the bucket',
                    'lt_net' => 'Lifetime net revenue up to the bucket',
                    'lt_arpu' => 'Average lifetime net revenue per registered user',
                    'lt_arppu' => 'Average lifetime net revenue per paying user',
                    'lt_arpv' => 'Average lifetime net revenue per visitor',
                    'lt_reg2pu' => 'Registration-to-paying-user conversion in %',
                ],
            ]);
        } catch (Throwable $e) {
            report($e);

            return Response::error('Could not list stats capabilities. The error was reported, tell the user to try again or rephrase.');
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function projects(): array
    {
        $searchConsoleAvailable = GscConnection::isAvailable();

        $rows = Project::query()
            ->with('gscConnection:id,project_id,site_url')
            ->get(['id', 'uuid', 'name', 'usage', 'page_views_seen_at'])
            ->map(fn (Project $project): array => [
                'id' => $project->id,
                'name' => $project->name,
                'tracks_registrations' => $project->hasUsages([Project::USAGE_USERS]),
                'tracks_subscriptions' => $project->hasUsages([Project::USAGE_SUBSCRIPTION]),
                'tracks_page_views' => $project->hasRecentPageViews(),
                'search_console_connected' => $searchConsoleAvailable && $project->gscConnection?->site_url !== null,
                // Connecting needs the Google consent screen in the browser, the assistant can only link there.
                ...($searchConsoleAvailable && $project->gscConnection?->site_url === null
                    ? ['search_console_setup_url' => route('projects.show', $project).'#search-console']
                    : []),
            ])
            ->all();

        return [['id' => StatsFilterDto::TRACK_ALL, 'name' => 'All projects'], ...$rows];
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
