<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StatsPaymentRequest;
use App\Jobs\TrackStatsPayment;
use Illuminate\Foundation\Bus\PendingDispatch;
use Illuminate\Http\JsonResponse;

use function Illuminate\Support\defer;

class StatsPaymentController extends Controller
{
    public function store(StatsPaymentRequest $request): JsonResponse
    {
        defer(fn (): PendingDispatch => dispatch(new TrackStatsPayment(input: $request->validated())));

        return response()->json([
            'message' => 'Stats Payment Request accepted.',
            'id' => $request->validated('id'),
        ]);
    }
}
