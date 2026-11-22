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
        // Zero amount transactions are no revenue (e.g. Paddle's payment method changes), so they are not stored.
        // Still answered with 200, an error would only make the client retry.
        if ((float) $request->validated('gross') === 0.0) {
            return response()->json([
                'message' => 'Stats Payment Request ignored: zero amount.',
                'id' => $request->validated('id'),
            ]);
        }

        defer(fn (): PendingDispatch => dispatch(new TrackStatsPayment(input: $request->validated())));

        return response()->json([
            'message' => 'Stats Payment Request accepted.',
            'id' => $request->validated('id'),
        ]);
    }
}
