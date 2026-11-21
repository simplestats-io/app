<?php

namespace App\Http\Middleware;

use App\Http\Controllers\BlogController;
use App\Http\Controllers\ChangelogController;
use App\Http\Controllers\ComplianceController;
use App\Http\Controllers\DocsController;
use App\Http\Controllers\DpaController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\ImprintController;
use App\Http\Controllers\SecurityController;
use App\Http\Controllers\TrademarkController;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class CheckAppType
{
    private const array MARKETING_CONTROLLERS = [
        HomeController::class,
        ImprintController::class,
        DpaController::class,
        TrademarkController::class,
        ComplianceController::class,
        SecurityController::class,
        BlogController::class,
        ChangelogController::class,
    ];

    /**
     * Self-hosted installs have no marketing site, and the docs are not built
     * there (docs/.vitepress/dist is gitignored), so in-app docs links go to the public docs.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (config('app.app_type') === 'saas') {
            return $next($request);
        }

        $controller = Str::before($request->route()?->getActionName() ?? '', '@');

        if ($controller === DocsController::class) {
            return redirect()->away(Str::finish(config('app.public_docs_url'), '/').$request->route('path'));
        }

        if (in_array($controller, self::MARKETING_CONTROLLERS, true)) {
            return to_route('login');
        }

        return $next($request);
    }
}
