<?php

namespace App\Http\Controllers;

use App\Helpers\ImportHelper;
use App\Http\DataProviders\DataImportIndexDataProvider;
use App\Http\Requests\DataImportRequest;
use App\Jobs\ProcessDataImportJob;
use App\Models\DataImport;
use App\Validators\BaseStatsValidator;
use Cache;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Sleep;
use Inertia\Inertia;
use Inertia\Response;
use Junges\TrackableJobs\Enums\TrackedJobStatus;

class DataImportController extends Controller
{
    public function __construct()
    {
        $this->authorizeResource(DataImport::class, 'dataImport');
    }

    public function index(DataImportIndexDataProvider $dataProvider): Response
    {
        return Inertia::render('DataImports/Index', $dataProvider->toArray());
    }

    public function store(DataImportRequest $request): RedirectResponse
    {
        $validated = $request->validated();
        $file = $validated['file'];
        unset($validated['file']);

        $dataImport = new DataImport(array_merge([
            'created_by' => $request->user()->id,
            'rows_max' => ImportHelper::countImportRows($file),
        ], $validated));

        $baseValidator = resolve(BaseStatsValidator::class);
        $baseValidator->validateImportFields($dataImport, ImportHelper::getImportFields($file));

        // only additional validation is done, we start the import...
        $dataImport->save();

        $filepath = $file->store('imports');
        dispatch(new ProcessDataImportJob($dataImport, $filepath));

        // w8 till the dispatched job is tracked
        Sleep::sleep(1);

        return to_route('data-import.index')->banner('Import started');
    }

    public function cancel(DataImport $dataImport): RedirectResponse
    {
        $this->authorize('cancel', $dataImport);

        Cache::put('cancel_data_import_'.$dataImport->id, true);

        // set rough defaults (the job is actually canceled in the queue)
        $dataImport->trackedJob->update([
            'status' => TrackedJobStatus::Failed->value,
            'finished_at' => now(),
        ]);

        return to_route('data-import.index')->banner('Import canceled');
    }

    public function rollback(DataImport $dataImport): RedirectResponse
    {
        $this->authorize('rollback', $dataImport);

        $model = $dataImport->type->getImporter($dataImport)->getModelClass();

        $model::where('data_import_id', $dataImport->id)->delete();

        $dataImport->trackedJob->update([
            'status' => TrackedJobStatus::Finished->value,
            'finished_at' => now(),
        ]);

        $dataImport->update(['rolled_back_at' => now()]);

        return to_route('data-import.index')->banner('Import rolled back');
    }
}
