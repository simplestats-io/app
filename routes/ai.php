<?php

use App\Http\Middleware\Mcp\AuthenticateMcpCaller;
use App\Http\Middleware\Mcp\RejectProjectApiTokens;
use App\Http\Middleware\SetTeamContext;
use App\Mcp\Servers\SimpleStatsServer;
use Illuminate\Support\Facades\Route;
use Laravel\Mcp\Facades\Mcp;

Mcp::local('simplestats', SimpleStatsServer::class);

// Dynamic client registration is public by design (RFC 7591) and every call
// creates an OAuth client row, so the discovery and registration routes are throttled.
Route::middleware('throttle:60,1')->group(function (): void {
    Mcp::oauthRoutes(oauthPrefix: config('passport.path'));
});

// routes/ai.php carries no middleware group, so everything is attached
// explicitly. Auth = OAuth access token (Passport) only; the transport is
// stateless, the token travels with every POST.
Mcp::web('mcp', SimpleStatsServer::class)
    ->middleware([
        RejectProjectApiTokens::class,
        'auth:api',
        AuthenticateMcpCaller::class,
        SetTeamContext::class,
        'throttle:mcp',
    ])
    ->name('mcp.web');
