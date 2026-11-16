<?php

namespace App\Imports\Fathom;

use App\Contracts\StatsImporter;
use App\Imports\StatsPageViewImport;
use App\Imports\StatsVisitorImport;
use App\Models\DataImport;
use App\Models\StatsVisitor;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithValidation;

/**
 * Preset on top of the raw imports: the export is mapped into raw visitor rows and raw
 * page view rows, which then run through StatsVisitorImport and StatsPageViewImport.
 * No chunk reading: a visitor's views can span several rows of the same bucket, so the
 * whole file is mapped first.
 */
class FathomImport implements StatsImporter, ToCollection, WithHeadingRow, WithValidation
{
    private ?StatsVisitorImport $visitorImport = null;

    public int $skippedViews = 0;

    public function __construct(protected DataImport $dataImport) {}

    public function getModelClass(): string
    {
        return StatsVisitor::class;
    }

    public function getLowestImportedDate(): CarbonInterface
    {
        return $this->visitorImport?->getLowestImportedDate() ?? now()->toImmutable();
    }

    public function updateProgress(): void
    {
        $this->visitorImport?->updateProgress();
    }

    public function rules(): array
    {
        $optional = array_fill_keys([
            ...FathomExportMapper::DIMENSION_COLUMNS,
            ...FathomExportMapper::METRIC_COLUMNS,
        ], ['nullable']);

        return array_merge($optional, [
            'date' => ['required', 'date'],
            'pathname' => ['required', 'string'],
            'pageviews' => ['required', 'integer', 'min:0'],
            'visits' => ['nullable', 'integer', 'min:0'],
            'uniques' => ['nullable', 'integer', 'min:0'],
        ]);
    }

    /**
     * @param  Collection<int, Collection<string, mixed>>  $collection
     */
    public function collection(Collection $collection): void
    {
        $mapper = resolve(FathomExportMapper::class);
        $buckets = $mapper->map($collection->map(fn (Collection $row): array => $row->all()), (string) $this->dataImport->id);
        $this->skippedViews = $mapper->skippedViews;

        // progress counts the synthesized visitors, not the (aggregated) file rows
        $this->dataImport->update([
            'rows_max' => max(1, collect($buckets)->sum(fn (array $bucket): int => count($bucket['visitors']))),
        ]);

        $this->visitorImport = new StatsVisitorImport($this->dataImport);
        $pageViewImport = new StatsPageViewImport($this->dataImport);

        $cacheLookups = StatsVisitor::$cacheTrackingLookups;
        StatsVisitor::$cacheTrackingLookups = true;

        try {
            foreach ($buckets as $bucket) {
                foreach (array_chunk($bucket['visitors'], StatsVisitorImport::BATCH_CHUNK_SIZE) as $visitorRows) {
                    StatsVisitor::query()->insertOrIgnore($this->sameColumns(array_map(
                        fn (array $row): array => $this->visitorImport->model($row)->getAttributes(),
                        $visitorRows,
                    )));
                }

                foreach (array_chunk($bucket['views'], StatsPageViewImport::CHUNK_SIZE) as $viewItems) {
                    $pageViewImport->importItems($viewItems);
                }
            }
        } finally {
            StatsVisitor::$cacheTrackingLookups = $cacheLookups;
            StatsVisitor::flushTrackingLookupCache();
        }

        $this->visitorImport->updateProgress();
    }

    /**
     * The model mutators can drop or move columns per row (a dotted utm_source becomes a
     * referer), while a batch insert needs the same columns on every row.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function sameColumns(array $rows): array
    {
        $columns = array_fill_keys(array_keys(array_merge(...$rows)), null);

        return array_map(fn (array $row): array => array_merge($columns, $row), $rows);
    }
}
