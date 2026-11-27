<?php

namespace App\Services\Gsc;

use App\Exceptions\GscReconnectRequiredException;
use App\Jobs\SyncGscDataJob;
use App\Models\GscConnection;
use App\Models\Project;
use App\Models\StatsKeywordDaily;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
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
        }

        return $connection;
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
