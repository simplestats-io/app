<?php

namespace App\Imports;

use App\Contracts\StatsImporter;
use App\Exceptions\ImportCanceledException;
use App\Helpers\PagePathHelper;
use App\Models\DataImport;
use App\Models\StatsVisitor;
use App\Services\StatsViewIngestService;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithValidation;

/**
 * Page views are attached to visitors that already exist (live-tracked or imported before),
 * matched by visitor_hash. Rows of unknown visitors are dropped. The rows go through the same
 * StatsViewIngestService as the client batches, so counters, the 500 views cap, the
 * aggregation cache and the project's page view markers behave exactly like live data.
 *
 * Page views carry no data_import_id, so this import cannot be rolled back on its own.
 * Rolling back the visitors import removes their views (FK cascade).
 */
class StatsPageViewImport implements StatsImporter, ToCollection, WithChunkReading, WithHeadingRow, WithValidation
{
    public const int CHUNK_SIZE = 1000;

    public int $counter = 0;

    public int $skippedRows = 0;

    private CarbonInterface $lowestImportedDate;

    public function __construct(protected DataImport $dataImport)
    {
        $this->lowestImportedDate = now()->toImmutable();
    }

    public function getModelClass(): string
    {
        return StatsVisitor::class;
    }

    public function getLowestImportedDate(): CarbonInterface
    {
        return $this->lowestImportedDate;
    }

    public function updateProgress(): void
    {
        $this->dataImport->update(['rows_imported' => $this->counter]);
    }

    public function rules(): array
    {
        return [
            'visitor_hash' => ['required', 'string'],
            'page' => ['required', 'string'],
            'views' => ['nullable', 'integer', 'min:1'],
            'time' => ['nullable', 'date'],
        ];
    }

    public function chunkSize(): int
    {
        return self::CHUNK_SIZE;
    }

    /**
     * @param  Collection<int, Collection<string, mixed>>  $collection
     */
    public function collection(Collection $collection): void
    {
        $items = $collection->map(fn (Collection $row): array => [
            'visitor_hash' => (string) $row['visitor_hash'],
            'page' => (string) $row['page'],
            'views' => (int) ($row['views'] ?? 1) ?: 1,
            'time' => $row['time'] ?? null,
        ])->all();

        $this->importItems($items);
        $this->counter += $collection->count();
        $this->updateProgress();
    }

    /**
     * @param  list<array{visitor_hash: string, page: string, views: int, time?: CarbonInterface|string|int|null}>  $items
     *
     * @throws ImportCanceledException
     */
    public function importItems(array $items): void
    {
        if (Cache::pull('cancel_data_import_'.$this->dataImport->id)) {
            throw new ImportCanceledException;
        }

        $visitorTimes = $this->visitorTimes(array_column($items, 'visitor_hash'));
        $viewItems = [];

        foreach ($items as $item) {
            $page = PagePathHelper::normalize(Str::before(Str::before($item['page'], '#'), '?'));
            $visitorTime = $visitorTimes[$item['visitor_hash']] ?? null;

            if (blank($page) || is_null($visitorTime)) {
                $this->skippedRows++;

                continue;
            }

            if ($visitorTime->lt($this->lowestImportedDate)) {
                $this->lowestImportedDate = $visitorTime;
            }

            $viewItems[] = [
                'visitor_hash' => $item['visitor_hash'],
                'page' => Str::limit($page, 252),
                'views' => $item['views'],
                'last_viewed_at' => blank($item['time'] ?? null)
                    ? $visitorTime->getTimestamp()
                    : CarbonImmutable::parse($item['time'])->getTimestamp(),
            ];
        }

        if ($viewItems === []) {
            return;
        }

        resolve(StatsViewIngestService::class)->ingest(
            $this->dataImport->project,
            (string) Str::uuid(),
            $viewItems,
            'import',
            retryUnmatched: false,
        );
    }

    /**
     * time_utc, not the team-local time: last_viewed_at is stored in UTC.
     *
     * @param  list<string>  $hashes
     * @return array<string, CarbonImmutable>
     */
    private function visitorTimes(array $hashes): array
    {
        return DB::table('stats_visitors')
            ->where('team_id', $this->dataImport->team_id)
            ->where('project_id', $this->dataImport->project_id)
            ->whereIn('visitor_hash', array_values(array_unique($hashes)))
            ->pluck('time_utc', 'visitor_hash')
            ->map(fn (string $time): CarbonImmutable => CarbonImmutable::parse($time, 'UTC'))
            ->all();
    }
}
