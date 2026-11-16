<?php

namespace App\Http\DataProviders\Api;

use App\DTOs\StatsFilterDto;
use App\Http\Requests\Api\ApiStatsFilterRequest;
use App\Models\Project;
use App\Services\StatsAggregationsService;
use App\Services\StatsService;

class ApiStatsGroupedDataProvider extends AbstractApiStatsDataProvider
{
    public function __construct(
        ApiStatsFilterRequest $request,
        private readonly StatsAggregationsService $statsAggregationsService,
    ) {
        parent::__construct($request);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $filters = $this->filters();
        $hiddenKeys = $this->hiddenKeys(Project::find($filters->projectId));

        $response = [
            'data' => $this->groupedData($filters, $hiddenKeys),
            'meta' => $this->meta($filters),
        ];

        if ($filters->shouldCompare()) {
            $response['data_previous'] = $this->groupedData(
                StatsService::createPrevFiltersForComparison($filters),
                $hiddenKeys,
            );
        }

        return $response;
    }

    /**
     * @param  array<string>  $hiddenKeys
     * @return array<int, array<string, mixed>>
     */
    private function groupedData(StatsFilterDto $filters, array $hiddenKeys): array
    {
        $data = $this->request->stats_type === StatsService::TOP_PAGES_TYPE
            ? $this->statsAggregationsService->getTopPagesData(
                $filters,
                $this->request->stats_sort ?? 'views',
                $this->request->limit ?? 10,
            )->values()->all()
            : $this->statsAggregationsService->getAggregatedGroupedData(
                $filters,
                $this->request->stats_type,
                $this->request->stats_sort ?? 'visitors',
                $this->request->limit ?? 10,
                withViews: true,
            )->values()->toArray();

        if ($hiddenKeys === []) {
            return $data;
        }

        return array_map(fn (array $item): array => array_diff_key($item, array_flip($hiddenKeys)), $data);
    }

    /**
     * @return array<string>
     */
    private function hiddenKeys(?Project $project): array
    {
        if (! $project instanceof Project) {
            return [];
        }

        $hidden = [];

        if (! $project->hasUsages([Project::USAGE_USERS])) {
            $hidden[] = 'reg';
            $hidden[] = 'cr';
            $hidden[] = 'dau';
        }

        if (! $project->hasUsages([Project::USAGE_PAYMENTS])) {
            $hidden[] = 'net';
        }

        return $hidden;
    }
}
