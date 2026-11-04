<?php

namespace App\Http\Middleware;

use App\Enums\FeedbackType;
use App\Models\Project;
use App\Services\LoginSummaryService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Inertia\Middleware;
use Tighten\Ziggy\Ziggy;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Defines the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     */
    public function share(Request $request): array
    {
        $isSsrPage = $this->shouldEnableSsr($request);

        if (! $isSsrPage) {
            config(['inertia.ssr.enabled' => false]);
        }

        $merge = [
            'canLogin' => Route::has('login'),
            'canRegister' => Route::has('register'), // this gets deactived through the fortify-config
            'branding' => config('app.branding'),
            'locale' => app()->getLocale(),
        ];

        // Ziggy + translations are ~30 KB per response and only needed on public pages:
        // the SSR render reads the ziggy prop (the browser uses the @routes global from
        // app.blade.php instead), and only public pages are translated. In-app pages get
        // fresh routes after a deploy via Inertia's asset-version reload.
        if ($isSsrPage || $request->is('blog', 'blog/*')) {
            $merge['ziggy'] = (new Ziggy)->toArray();
            $merge['translations'] = fn (): array => $this->getTranslations();
            $merge['supportedLocales'] = array_keys(config('locales.supported', []));
            $merge['localeConfig'] = config('locales.supported');
            $merge['defaultLocale'] = config('locales.default');
            $merge['localeUrls'] = $this->buildLocaleUrls($request);
        }

        if (auth()->user()) {
            $merge['isAdmin'] = auth()->user()->isAdmin();
            $merge['loginSummaryEligible'] = resolve(LoginSummaryService::class)->isEligible($request->user());
            $merge['feedbackTypes'] = FeedbackType::toOptions();

            if ($currentTeam = auth()->user()->currentTeam) {
                $merge['onboardingPassed'] = $currentTeam->projects()->first()?->name;
                $merge['teamCurrency'] = $currentTeam->currency;
                $merge['currentPlanOptions'] = $currentTeam->owner->sparkPlan()->options;
                $merge['teamTracksUsers'] = $currentTeam->tracksUsage(Project::USAGE_USERS);
                $merge['teamTracksPayments'] = $currentTeam->tracksUsage(Project::USAGE_PAYMENTS);
                $merge['teamTracksSubscription'] = $currentTeam->tracksUsage(Project::USAGE_SUBSCRIPTION);
            }
        }

        return array_merge(parent::share($request), $merge);
    }

    /**
     * Only enable SSR for Home and Auth pages.
     */
    private function shouldEnableSsr(Request $request): bool
    {
        $publicPaths = config('locales.public_paths', []);
        $defaultLocale = config('locales.default');
        $supportedLocales = array_keys(config('locales.supported', []));

        $patterns = collect($publicPaths)
            ->map(fn (string $path): string => ltrim($path, '/'))
            ->flatMap(function (string $path) use ($defaultLocale, $supportedLocales) {
                $wildcardPath = $path === '' ? '/' : "$path/*";

                return collect($supportedLocales)
                    ->reject(fn ($l): bool => $l === $defaultLocale)
                    ->flatMap(fn ($l): array => $path === ''
                        ? [$l]
                        : ["$l/$path", "$l/$path/*"]
                    )
                    ->prepend($wildcardPath)
                    ->prepend($path);
            })->all();

        return $request->is($patterns);
    }

    /**
     * Load the JSON translations for the active locale.
     *
     * @return array<string, string>
     */
    private function getTranslations(): array
    {
        $locale = app()->getLocale();
        $path = lang_path("{$locale}.json");

        if (! file_exists($path)) {
            return [];
        }

        return json_decode(file_get_contents($path), true);
    }

    /**
     * Build URLs for the current page in each supported locale.
     *
     * @return array<string, string>
     */
    private function buildLocaleUrls(Request $request): array
    {
        $supported = array_keys(config('locales.supported', []));
        $default = config('locales.default', 'en');
        $path = $request->getPathInfo();

        // Strip current locale prefix from path
        $segment = $request->segment(1);
        if ($segment && $segment !== $default && in_array($segment, $supported)) {
            $path = '/'.ltrim(substr($path, strlen('/'.$segment)), '/');
        }

        if ($path === '') {
            $path = '/';
        }

        $urls = [];
        foreach ($supported as $locale) {
            if ($locale === $default) {
                $urls[$locale] = $path.(str_contains($path, '?') ? '&' : '?').'hl='.$default;
            } else {
                $urls[$locale] = '/'.$locale.($path === '/' ? '' : $path);
            }
        }

        return $urls;
    }
}
