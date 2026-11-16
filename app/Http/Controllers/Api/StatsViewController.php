<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StatsViewRequest;
use App\Jobs\TrackStatsViews;
use App\Services\StatsViewIngestService;
use App\Services\StatsViewItemsSanitizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Context;

class StatsViewController extends Controller
{
    public function store(StatsViewRequest $request, StatsViewItemsSanitizer $sanitizer, StatsViewIngestService $ingestService): JsonResponse
    {
        $batchId = $request->validated('batch_id');
        $duplicate = $ingestService->isKnownBatch((int) Context::get('project_id'), $batchId);
        ['items' => $items, 'rejected' => $rejected] = $sanitizer->sanitize($request->validated('views'));

        // Dispatched directly instead of deferred: a lost dispatch must surface as a 500,
        // so the client retries the batch, instead of a 200 for views that never land.
        if (! $duplicate && $items !== []) {
            dispatch(new TrackStatsViews(input: [
                'batch_id' => $batchId,
                'items' => $items,
                'mode' => $request->validated('meta.mode'),
            ]));
        }

        return response()->json([
            'message' => 'Stats Views Request accepted.',
            'batch_id' => $batchId,
            'accepted' => count($items),
            'rejected' => $rejected,
            'duplicate' => $duplicate,
        ]);
    }
}
