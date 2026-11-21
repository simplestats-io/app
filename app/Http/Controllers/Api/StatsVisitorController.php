<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StatsVisitorRequest;
use App\Jobs\TrackStatsVisitor;
use App\Services\BotDetectionService;
use App\Services\ReferrerSpamService;
use Illuminate\Foundation\Bus\PendingDispatch;
use Illuminate\Http\JsonResponse;

use function Illuminate\Support\defer;

class StatsVisitorController extends Controller
{
    public function store(StatsVisitorRequest $request, ReferrerSpamService $referrerSpam): JsonResponse
    {
        if (BotDetectionService::isBot(urldecode($request->user_agent))
            || $referrerSpam->isSpam($request->track_source)) {
            // we respond, cause else the client would retry these requests again and again if they use queues...
            return response()->json(['message' => 'Bots are not allowed'], 200);
        }

        if ($spamDomain = $referrerSpam->matchedDomain($request->track_referer)) {
            // The listed domain, not the exact host: one client cache entry per spam
            // domain, however many random subdomains a bot cycles through.
            return response()->json([
                'message' => 'Bots are not allowed',
                'skip' => [['type' => 'referer', 'value' => $spamDomain, 'ttl' => ReferrerSpamService::SKIP_TTL]],
            ]);
        }

        defer(fn (): PendingDispatch => dispatch(new TrackStatsVisitor(input: $request->validated())));

        return response()->json([
            'message' => 'Stats Visitor Request accepted.',
            'visitor_hash' => $request->validated('visitor_hash'),
        ]);
    }
}
