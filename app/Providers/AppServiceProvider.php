<?php

namespace App\Providers;

use App\Http\Middleware\ShareInertiaData;
use App\Models\PersonalAccessToken;
use App\Models\StatsUser;
use App\Models\StatsVisitor;
use App\Models\User;
use App\Observers\UserObserver;
use App\PulseUsers;
use App\Services\Ai\NullConversationStore;
use App\Services\LocationService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;
use Laravel\Ai\Contracts\ConversationStore;
use Laravel\Ai\Storage\DatabaseConversationStore;
use Laravel\Pulse\Contracts\ResolvesUsers;
use Laravel\Sanctum\Sanctum;
use Spatie\Health\Checks\Checks\CacheCheck;
use Spatie\Health\Checks\Checks\DatabaseCheck;
use Spatie\Health\Checks\Checks\DebugModeCheck;
use Spatie\Health\Checks\Checks\EnvironmentCheck;
use Spatie\Health\Checks\Checks\HorizonCheck;
use Spatie\Health\Checks\Checks\RedisCheck;
use Spatie\Health\Checks\Checks\RedisMemoryUsageCheck;
use Spatie\Health\Checks\Checks\ScheduleCheck;
use Spatie\Health\Checks\Checks\UsedDiskSpaceCheck;
use Spatie\Health\Facades\Health;
use Spatie\OgImage\Facades\OgImage;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        if ($this->app->environment('local')) {
            $this->app->register(\Laravel\Telescope\TelescopeServiceProvider::class);
            $this->app->register(TelescopeServiceProvider::class);
        }

        $this->app->singleton(ResolvesUsers::class, PulseUsers::class);

        // Curated auth.user payload instead of Jetstream's full-model share.
        $this->app->bind(
            \Laravel\Jetstream\Http\Middleware\ShareInertiaData::class,
            ShareInertiaData::class,
        );

        // Singleton so the datacenter check and the geo mutator share one
        // memoized lookup per IP within a worker process.
        $this->app->singleton(LocationService::class);

        // While impersonating (and in demo mode, where all visitors share one
        // account) AI conversations are not persisted: the chat still works,
        // but nothing lands in the user's conversation history. Scoped, not
        // singleton: the decision depends on the session and must be
        // re-evaluated per request under Octane.
        $this->app->scoped(ConversationStore::class, function (): NullConversationStore|DatabaseConversationStore {
            $ephemeral = app()->environment('demo') || auth()->user()?->isImpersonated();

            return $ephemeral
                ? new NullConversationStore
                : new DatabaseConversationStore(config('ai.conversations.connection'));
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $checks = [
            DatabaseCheck::new(),
            CacheCheck::new(),
            RedisCheck::new(),
            RedisMemoryUsageCheck::new(),
            ScheduleCheck::new()->heartbeatMaxAgeInMinutes(5),
            UsedDiskSpaceCheck::new(),
            EnvironmentCheck::new(),
            DebugModeCheck::new(),
        ];

        if ($this->queueRunsOnHorizon()) {
            array_unshift($checks, HorizonCheck::new());
        }

        Health::checks($checks);

        User::observe(UserObserver::class);

        Sanctum::$personalAccessTokenModel = PersonalAccessToken::class;

        // Stable, short aliases for polymorphic stats_custom_properties.propertyable_type,
        // so the column stores 'stats_user' / 'stats_visitor' instead of full class
        // names (shorter index values, decoupled from class renames).
        Relation::morphMap([
            'stats_user' => StatsUser::class,
            'stats_visitor' => StatsVisitor::class,
        ]);

        Date::use(CarbonImmutable::class);
        Model::preventLazyLoading(! app()->isProduction());
        Model::shouldBeStrict(! app()->isProduction());
        Model::unguard();

        DB::prohibitDestructiveCommands(
            $this->app->isProduction(),
        );

        Vite::usePrefetchStrategy('aggressive');

        // spatie/laravel-og-image is only installed in the SaaS build
        // (composer.saas.json), so OgImage must never be called outside
        // this guard. The import above is harmless (use statements don't autoload).
        if (config('app.app_type') === 'saas') {
            OgImage::fallbackUsing(fn (Request $request): Factory|View => view('og-image.fallback', [
                'title' => config('app.name'),
                'tagline' => 'Revenue attribution analytics for Laravel apps',
                'appUrl' => str_replace(['https://', 'http://'], '', config('app.url')),
            ]));
        }

        Gate::define('viewPulse', fn (User $user): bool => $user->isAdmin());
    }

    /**
     * Horizon only drives Redis queues, so an installation that runs its queue
     * on any other driver has no Horizon process for the health check to find
     * and would report an unhealthy application forever.
     */
    private function queueRunsOnHorizon(): bool
    {
        $connection = Config::string('queue.default');

        return config("queue.connections.{$connection}.driver") === 'redis';
    }
}
