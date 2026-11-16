<?php

namespace App\Imports;

use App\Concerns\HasCounterByStatsUser;
use App\Concerns\HasStatsTracking;
use App\Concerns\HasStatsUserAssurance;
use App\Contracts\StatsImporter;
use App\Exceptions\ImportCanceledException;
use App\Models\DataImport;
use App\Services\SubscriptionService;
use App\Validators\BaseStatsValidator;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Maatwebsite\Excel\Concerns\ToModel;
use Maatwebsite\Excel\Concerns\WithBatchInserts;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithValidation;

abstract class BaseStatsImport implements StatsImporter, ToModel, WithBatchInserts, WithChunkReading, WithHeadingRow, WithValidation
{
    const BATCH_CHUNK_SIZE = 1000;

    public int $counter = 0;

    // we need to take track of the lowest imported data (for aggregation invalidation)
    private CarbonInterface $lowestImportedDate;

    private readonly int $counterUpdateStep;

    public function __construct(
        protected DataImport $dataImport,
        protected string $modelClass,
        protected BaseStatsValidator $validator
    ) {
        // https://docs.laravel-excel.com/3.1/imports/validation.html#database-transactions
        // We need to make inbetween db updates, so we disable transactions.
        // As we anyway use Batches and Chunks and only the current batch is wrapped in a transaction,
        // it has not really a difference...
        config(['excel.transactions.handler' => 'null']);

        $this->counterUpdateStep = max(1, floor($this->dataImport->rows_max / 100));

        $this->lowestImportedDate = now()->toImmutable();
    }

    public function getLowestImportedDate(): CarbonInterface
    {
        return $this->lowestImportedDate;
    }

    public function getModelClass(): string
    {
        return $this->modelClass;
    }

    /**
     * @return Model|null
     *
     * @throws ImportCanceledException
     */
    public function model(array $row)
    {
        if (Cache::get('cancel_data_import_'.$this->dataImport->id)) {
            Cache::forget('cancel_data_import_'.$this->dataImport->id);
            throw new ImportCanceledException;
        }

        // check if the users team plan has the rights to create more stats records;
        // counterpart of the EnsureWithinEventLimit middleware on the API ingest routes
        // (the counter delta is needed because batch inserts bypass the StatsObserver increments)
        if (resolve(SubscriptionService::class)->hasReachedMonthlyEventLimit($this->dataImport->team, $this->counter)) {
            throw new AuthorizationException('Monthly event limit reached.');
        }

        $this->counter++;

        $time = CarbonImmutable::parse($row['time']);
        if ($this->lowestImportedDate->gt($time)) {
            $this->lowestImportedDate = $time;
        }

        // limit the interval of the rows_imported value, so that we do not need to make thousands of queries here.
        if ($this->counter % $this->counterUpdateStep === 0 || $this->counter == $this->dataImport->rows_max) {
            $this->updateProgress();
        }

        $modelConcerns = class_uses(new $this->modelClass);

        // team and project id are normally set dynamically
        // this is safe, as we've validated the rows/cols before...
        return $this->modelClass::unguarded(function () use ($row, $modelConcerns): object {
            $uniqueProject = [
                'team_id' => $this->dataImport->team_id,
                'project_id' => $this->dataImport->project_id,
            ];

            // prepare validation tweaks data as we need/want it here.
            // data_import_id tags the imported row so rollback deletes exactly this import's rows,
            // regardless of whether the customer uses integer or UUID ids. It is intentionally not
            // part of $uniqueProject, so placeholder users created by ensureStatsUser() stay untagged
            // and a payment rollback never cascades away a user's live-tracked payments.
            $data = array_merge($this->validator->prepareForValidation($row), $uniqueProject, [
                'data_import_id' => $this->dataImport->id,
            ]);

            $model = new $this->modelClass($data);

            // as batch in/upsert does not use eloquent, we have to add these things manually
            if (in_array(HasCounterByStatsUser::class, $modelConcerns)) {
                $model->counter = $model->getCounter();
            }

            if (in_array(HasStatsTracking::class, $modelConcerns)) {
                $model->resolveTrackingLookups();
            }

            if (in_array(HasStatsUserAssurance::class, $modelConcerns)) {
                $model->ensureStatsUser($uniqueProject);
            }

            return $model;
        });
    }

    public function updateProgress(): void
    {
        $this->dataImport->update([
            'rows_imported' => $this->counter,
        ]);
    }

    public function prepareForValidation(array $data, $index): array
    {
        return $this->validator->prepareForValidation($data);
    }

    public function rules(): array
    {
        return $this->validator->rules();
    }

    public function batchSize(): int
    {
        return self::BATCH_CHUNK_SIZE;
    }

    public function chunkSize(): int
    {
        return self::BATCH_CHUNK_SIZE;
    }
}
