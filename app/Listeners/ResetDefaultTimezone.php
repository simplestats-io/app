<?php

namespace App\Listeners;

use Laravel\Octane\Events\RequestReceived;

/**
 * AppHelper::ensureTeamTimezone() calls date_default_timezone_set(), which is
 * process-wide. Octane resets the config per request, but not PHP's default
 * timezone, so the next request in the same worker would run in the previous
 * team's timezone (and store e.g. ingest timestamps shifted).
 */
class ResetDefaultTimezone
{
    public function handle(RequestReceived $event): void
    {
        date_default_timezone_set($event->sandbox->make('config')->get('app.timezone'));
    }
}
