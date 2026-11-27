<?php

namespace App\Services\Gsc;

use App\DTOs\StatsFilterDto;
use App\Exceptions\GscReconnectRequiredException;
use App\Jobs\SyncGscDataJob;
use App\Models\GscConnection;
use App\Models\Project;
use App\Models\StatsKeywordDaily;
use App\Models\StatsTrackReferer;
use App\Models\StatsVisitor;
use App\Models\User;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\GoogleProvider;
use Laravel\Socialite\Two\User as GoogleUser;
use Throwable;

class GscConnectionService
{
    public function __construct(
        private readonly GscApiService $api,
        private readonly GscSyncService $sync,
    ) {}

    public function googleDriver(): GoogleProvider
    {
        /** @var GoogleProvider $driver */
        $driver = Socialite::buildProvider(GoogleProvider::class, [
            ...config('services.google_search_console'),
            'redirect' => route('gsc-connections.store'),
        ]);

        return $driver;
    }

    public function connect(Project $project, User $user, GoogleUser $googleUser): GscConnection
    {
        $connection = $project->gscConnection()->firstOrNew();
        $sameAccount = $connection->exists && $connection->google_email === $googleUser->getEmail();

        // Google only returns a refresh token on the consent screen, keep the old one otherwise.
        $refreshToken = $googleUser->refreshToken;

        if (blank($refreshToken)) {
            $refreshToken = $sameAccount ? $connection->refresh_token : null;
        }

        $connection->fill([
            'team_id' => $project->team_id,
            'user_id' => $user->id,
            'google_email' => $googleUser->getEmail(),
            'access_token' => $googleUser->token,
            'refresh_token' => $refreshToken,
            'expires_at' => now()->addSeconds($googleUser->expiresIn ?: 3600),
            'reconnect_required_at' => null,
        ]);

        // Another Google account may not see the previously linked property.
        if (! $sameAccount) {
            $connection->site_url = null;
        }

        $connection->save();

        if ($connection->site_url !== null) {
            dispatch(new SyncGscDataJob($connection));
        } else {
            $this->linkMatchingSite($connection, $project);
        }

        return $connection;
    }

    /**
     * Connects another project with a Google account the user already connected in this
     * team, so they skip the Google sign-in. Only their own grants, never a colleague's.
     */
    public function connectWithExistingAccount(Project $project, User $user, string $googleEmail): ?GscConnection
    {
        $source = GscConnection::query()
            ->whereBelongsTo($user)
            ->where('google_email', $googleEmail)
            ->whereNull('reconnect_required_at')
            ->whereNotNull('refresh_token')
            ->first();

        if ($source === null) {
            return null;
        }

        $connection = $project->gscConnection()->firstOrNew();
        $connection->fill([
            'team_id' => $project->team_id,
            'user_id' => $user->id,
            'google_email' => $source->google_email,
            'access_token' => $source->access_token,
            'refresh_token' => $source->refresh_token,
            'expires_at' => $source->expires_at,
            'site_url' => null,
            'reconnect_required_at' => null,
        ])->save();

        $this->linkMatchingSite($connection, $project);

        return $connection;
    }

    /**
     * Google accounts the user can reuse for another project of the current team.
     *
     * @return list<string>
     */
    public function reusableAccounts(User $user, Project $project): array
    {
        return GscConnection::query()
            ->whereBelongsTo($user)
            ->where('project_id', '!=', $project->id)
            ->whereNull('reconnect_required_at')
            ->whereNotNull('refresh_token')
            ->distinct()
            ->orderBy('google_email')
            ->pluck('google_email')
            ->all();
    }

    /**
     * The property that belongs to the project's domain: a domain property first, else
     * the https (or http) url prefix property of the root. Subdomains never match.
     *
     * @param  list<string>  $sites
     */
    public function matchingSite(Project $project, array $sites): ?string
    {
        $host = $this->normalizeHost($project->domain);

        if ($host === '') {
            return null;
        }

        $candidates = collect($sites)->filter(function (string $site) use ($host): bool {
            if (Str::startsWith($site, 'sc-domain:')) {
                return Str::lower(Str::after($site, 'sc-domain:')) === $host;
            }

            return $this->normalizeHost($site) === $host && in_array(parse_url($site, PHP_URL_PATH), [null, '', '/'], true);
        });

        return $candidates->first(fn (string $site): bool => Str::startsWith($site, 'sc-domain:'))
            ?? $candidates->first(fn (string $site): bool => Str::startsWith($site, 'https://'))
            ?? $candidates->first();
    }

    private function linkMatchingSite(GscConnection $connection, Project $project): void
    {
        $siteUrl = $this->matchingSite($project, $this->sites($connection)['sites']);

        if ($siteUrl !== null) {
            $this->selectSite($connection, $siteUrl);
        }
    }

    private function normalizeHost(?string $value): string
    {
        $host = parse_url((string) $value, PHP_URL_HOST) ?: parse_url('https://'.$value, PHP_URL_HOST) ?: '';

        return Str::of($host)->lower()->replaceStart('www.', '')->toString();
    }

    /**
     * @return array{sites: list<string>, error: string|null}
     */
    public function sites(GscConnection $connection): array
    {
        try {
            return ['sites' => $this->api->sites($connection), 'error' => null];
        } catch (GscReconnectRequiredException) {
            return ['sites' => [], 'error' => 'reconnect'];
        } catch (Throwable $exception) {
            report($exception);

            return ['sites' => [], 'error' => 'unavailable'];
        }
    }

    /**
     * Dashboard state of the Keywords tab. Without a linked property the tab only shows
     * up as a hint, and only for a project that already gets traffic from Google: that
     * is when people wonder what was searched for.
     *
     * @return array{visible: bool, suggest: bool, canConnect: bool, connectUrl: string|null}
     */
    public function dashboardState(StatsFilterDto $filters, bool $visible): array
    {
        $state = ['visible' => $visible, 'suggest' => false, 'canConnect' => false, 'connectUrl' => null];

        $project = ! $visible && $filters->projectId && GscConnection::isAvailable()
            ? Project::find($filters->projectId)
            : null;

        if ($project === null || ! $this->hasRecentGoogleTraffic($project)) {
            return $state;
        }

        return [
            ...$state,
            'suggest' => true,
            'canConnect' => Gate::allows('update', $project),
            'connectUrl' => route('projects.show', $project).'#search-console',
        ];
    }

    /**
     * Cached for a day: the dashboard asks on every load, the answer barely changes.
     */
    private function hasRecentGoogleTraffic(Project $project): bool
    {
        return Cache::remember("gsc-google-traffic:{$project->id}", now()->addDay(), function () use ($project): bool {
            $googleReferers = StatsTrackReferer::query()
                ->where('team_id', $project->team_id)
                ->where(fn (Builder $query) => $query->where('name', 'like', 'google.%')->orWhere('name', 'like', '%.google.%'))
                ->pluck('id');

            return $googleReferers->isNotEmpty() && StatsVisitor::query()
                ->whereBelongsTo($project)
                ->where('time', '>=', now()->subDays(30))
                ->whereIn('stats_track_referer_id', $googleReferers)
                ->exists();
        });
    }

    /**
     * @return array{sites: list<string>, suggested: string|null, error: string|null}
     */
    public function sitesWithSuggestion(GscConnection $connection, Project $project): array
    {
        $result = $this->sites($connection);

        return [...$result, 'suggested' => $this->matchingSite($project, $result['sites'])];
    }

    public function selectSite(GscConnection $connection, string $siteUrl): void
    {
        if ($connection->site_url === $siteUrl) {
            return;
        }

        DB::transaction(function () use ($connection, $siteUrl): void {
            $this->deleteKeywordData($connection);

            $connection->update([
                'site_url' => $siteUrl,
                'last_synced_at' => null,
                'last_synced_until_date' => null,
            ]);
        });

        $this->sync->queueBackfill($connection);
    }

    public function disconnect(GscConnection $connection): void
    {
        $this->revokeToken($connection);

        DB::transaction(function () use ($connection): void {
            $this->deleteKeywordData($connection);
            $connection->delete();
        });
    }

    private function deleteKeywordData(GscConnection $connection): void
    {
        StatsKeywordDaily::withoutGlobalScopes()->where('project_id', $connection->project_id)->delete();
    }

    /**
     * Best effort: the local tokens are gone either way, a failed revoke only leaves
     * the grant listed in the user's Google account.
     */
    private function revokeToken(GscConnection $connection): void
    {
        // Revoking ends the whole grant of this Google account, other projects connected
        // with the same account would silently lose their access.
        $sharedGrant = GscConnection::withoutGlobalScopes()
            ->where('google_email', $connection->google_email)
            ->whereKeyNot($connection->getKey())
            ->exists();

        if ($sharedGrant) {
            return;
        }

        try {
            Http::asForm()->timeout(10)->post('https://oauth2.googleapis.com/revoke', [
                'token' => $connection->refresh_token ?? $connection->access_token,
            ]);
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
