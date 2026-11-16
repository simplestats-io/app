<?php

namespace App\Http\DataProviders;

use App\DTOs\StatsFilterDto;
use App\Http\Requests\StatsFilterRequest;
use App\Models\Project;
use App\Services\StatsDetailsService;
use App\Services\StatsService;

class StatsGroupsDataProvider extends AbstractDataProvider
{
    public function __construct(
        private readonly StatsFilterRequest $request,
        private readonly StatsDetailsService $statsDetailsService,
    ) {}

    public function toArray(): array
    {
        $this->rememberSelectedGroupType();

        $filters = StatsFilterDto::fromRequest($this->request);

        $response = [
            'data' => $this->fetchGroupedData($filters),
        ];

        if ($filters->shouldCompare()) {
            $response['data_previous'] = $this->fetchGroupedData(
                StatsService::createPrevFiltersForComparison($filters),
            );
        }

        return $response;
    }

    private function rememberSelectedGroupType(): void
    {
        if (! isset($this->request->group_type)) {
            return;
        }

        // Keyed by the project's public uuid ("0" for all projects), because the
        // frontend reads these settings via filters.project_id, which is the uuid.
        $projectKey = $this->request->project_id
            ? Project::whereKey($this->request->project_id)->value('uuid') ?? 0
            : 0;

        $this->request->user()->updateSettings(
            "selectedGroupTypes.{$projectKey}.{$this->request->group_type}",
            $this->request->stats_type === 'property' ? (int) $this->request->property_name_id : $this->request->stats_type,
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchGroupedData(StatsFilterDto $filters): array
    {
        return $this->statsDetailsService->getGroupedData(
            $filters,
            $this->request->stats_type,
            $this->request->limit ?? 5, // must be equal to Load more in StatsGroupTable.vue
            $this->request->stats_sort,
            $this->request->property_name_id,
            $this->request->boolean('lifetime'),
            $this->request->input('metric') === 'mrr',
            $this->request->input('metric') === 'time_to_convert',
            // 'nau' implies the retention columns: the NAU metric page shows both,
            // and a request only carries one metric value.
            in_array($this->request->input('metric'), ['retention', 'nau'], true),
            $this->request->input('metric') === 'nau',
            withViews: true,
        );
    }
}
