<?php

namespace App\Providers;

use App\Http\Controllers\HealthUpController;
use App\Http\Controllers\WebManifestController;
use App\Models\Project;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

class RouteServiceProvider extends ServiceProvider
{
    /**
     * The path to your application's "home" route.
     *
     * Typically, users are redirected here after authentication.
     *
     * @var string
     */
    public const HOME = '/dashboard';

    private const int API_RATE_LIMIT_DEFAULT = 200;

    private const int MCP_RATE_LIMIT = 60;

    /**
     * Define your route model bindings, pattern filters, and other route configuration.
     */
    public function boot(): void
    {
        RateLimiter::for('api', function (Request $request) {
            $limit = self::API_RATE_LIMIT_DEFAULT;
            $key = $request->ip();

            $project = $request->user();

            if ($project instanceof Project) {
                $plan = $project->team?->billingOwner?->sparkPlan();
                $limit = $plan?->options['ApiRateLimit'] ?? self::API_RATE_LIMIT_DEFAULT;
                $key = 'project:'.$project->id;
            }

            return Limit::perMinute($limit)->by($key);
        });

        RateLimiter::for('mcp', function (Request $request) {
            $project = $request->user();

            return $project instanceof Project
                ? Limit::perMinute(self::MCP_RATE_LIMIT)->by('mcp:'.$project->id)
                : Limit::perMinute(10)->by($request->ip());
        });

        // The vendor passkey controller type-hints the package model, whose implicit
        // binding resolves by numeric id and answers a foreign passkey with 403.
        Route::bind('passkey', function (string $value) {
            $user = auth()->user();

            abort_unless($user && Str::isUuid($value), 404);

            return $user->passkeys()->where('uuid', $value)->firstOrFail();
        });

        $this->routes(function (): void {
            // Outside the web group on purpose: health probes and browsers fetching the
            // manifest hit these constantly and must not create a session per request.
            Route::get('up', HealthUpController::class)->name('health.up');
            Route::get('site.webmanifest', WebManifestController::class)->name('web-manifest');

            Route::middleware('api')
                ->prefix('api')
                ->group(base_path('routes/api.php'));

            Route::middleware('web')
                ->group(base_path('routes/web.php'));
        });
    }
}
