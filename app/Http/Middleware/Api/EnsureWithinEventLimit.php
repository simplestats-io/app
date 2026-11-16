<?php

namespace App\Http\Middleware\Api;

use App\Services\SubscriptionService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureWithinEventLimit
{
    /** Seconds the page view buffer of the client stops sending after this response. */
    public const int PAUSE_SECONDS = 3600;

    public function __construct(
        protected SubscriptionService $subscriptionService,
    ) {}

    /**
     * Reject ingest requests once the team's monthly event limit is reached.
     * Counterpart for data imports: BaseStatsImport::model().
     *
     * We respond with 200 (not 403) so the client treats it as terminal and
     * drops the request instead of retrying it for the rest of the month,
     * which would otherwise back up the client queue and, after the counter
     * resets, write the backlog into the following month with an old timestamp.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $project = $request->user();

        if ($project && $this->subscriptionService->hasReachedMonthlyEventLimit($project->team)) {
            return response()->json(['message' => 'Monthly event limit reached.', 'pause' => self::PAUSE_SECONDS]);
        }

        return $next($request);
    }
}
