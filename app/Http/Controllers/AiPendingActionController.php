<?php

namespace App\Http\Controllers;

use App\Helpers\AppHelper;
use App\Http\Requests\AiPendingActionRequest;
use App\Services\Ai\AiPendingActionService;
use Illuminate\Http\JsonResponse;

class AiPendingActionController extends Controller
{
    public function confirm(AiPendingActionRequest $request, string $pendingAction, AiPendingActionService $pendingActions): JsonResponse
    {
        AppHelper::ensureTeamTimezone();

        return response()->json($pendingActions->confirm($pendingAction, $request->string('conversation_id')->toString(), $request->user()));
    }

    public function destroy(AiPendingActionRequest $request, string $pendingAction, AiPendingActionService $pendingActions): JsonResponse
    {
        $pendingActions->cancel($pendingAction, $request->string('conversation_id')->toString(), $request->user());

        return response()->json(['status' => 'cancelled', 'message' => 'Cancelled. Nothing was changed.']);
    }
}
