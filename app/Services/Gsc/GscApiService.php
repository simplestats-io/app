<?php

namespace App\Services\Gsc;

use App\Exceptions\GscReconnectRequiredException;
use App\Models\GscConnection;
use Carbon\CarbonInterface;
use Generator;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class GscApiService
{
    public const string SCOPE = 'https://www.googleapis.com/auth/webmasters.readonly';

    private const string API_URL = 'https://searchconsole.googleapis.com/webmasters/v3';

    private const int ROW_LIMIT = 25000;

    /**
     * @return list<string>
     */
    public function sites(GscConnection $connection): array
    {
        $response = $this->request($connection, fn (string $token): Response => Http::withToken($token)
            ->timeout(15)
            ->get(self::API_URL.'/sites'));

        $sites = [];

        foreach ((array) $response->json('siteEntry', []) as $site) {
            if (($site['permissionLevel'] ?? '') !== 'siteUnverifiedUser') {
                $sites[] = (string) $site['siteUrl'];
            }
        }

        sort($sites);

        return $sites;
    }

    /**
     * @return Generator<int, array{keys: array{0: string, 1: string, 2: string}, clicks: int, impressions: int, position: float}>
     */
    public function searchAnalytics(GscConnection $connection, CarbonInterface $startDate, CarbonInterface $endDate): Generator
    {
        $startRow = 0;

        do {
            $response = $this->request($connection, fn (string $token): Response => Http::withToken($token)
                ->timeout(60)
                ->post(self::API_URL.'/sites/'.rawurlencode((string) $connection->site_url).'/searchAnalytics/query', [
                    'startDate' => $startDate->format('Y-m-d'),
                    'endDate' => $endDate->format('Y-m-d'),
                    'dimensions' => ['date', 'query', 'page'],
                    'rowLimit' => self::ROW_LIMIT,
                    'startRow' => $startRow,
                ]));

            $rows = $response->json('rows', []);

            yield from $rows;

            $startRow += self::ROW_LIMIT;
        } while (count($rows) === self::ROW_LIMIT);
    }

    /**
     * @param  callable(string): Response  $send
     */
    private function request(GscConnection $connection, callable $send): Response
    {
        $response = $send($this->accessToken($connection));

        // The stored expiry is only a hint, a rejected token gets one refresh before giving up.
        if ($response->status() === 401) {
            $response = $send($this->accessToken($connection, forceRefresh: true));
        }

        if (in_array($response->status(), [401, 403], true)) {
            $connection->markReconnectRequired();

            throw new GscReconnectRequiredException('Search Console access was revoked or lost.');
        }

        if (! $response->successful()) {
            throw new RuntimeException('Search Console API request failed with status '.$response->status());
        }

        return $response;
    }

    private function accessToken(GscConnection $connection, bool $forceRefresh = false): string
    {
        if (! $forceRefresh && ! $connection->isTokenExpired()) {
            return $connection->access_token;
        }

        if (blank($connection->refresh_token)) {
            $connection->markReconnectRequired();

            throw new GscReconnectRequiredException('Search Console connection has no refresh token.');
        }

        $response = Http::asForm()->timeout(15)->post('https://oauth2.googleapis.com/token', [
            'client_id' => config('services.google_search_console.client_id'),
            'client_secret' => config('services.google_search_console.client_secret'),
            'refresh_token' => $connection->refresh_token,
            'grant_type' => 'refresh_token',
        ]);

        // invalid_grant: the user revoked access or the refresh token expired.
        if ($response->status() === 400 && $response->json('error') === 'invalid_grant') {
            $connection->markReconnectRequired();

            throw new GscReconnectRequiredException('Search Console refresh token is no longer valid.');
        }

        if (! $response->successful()) {
            throw new RuntimeException('Search Console token refresh failed with status '.$response->status());
        }

        $connection->update([
            'access_token' => $response->json('access_token'),
            'expires_at' => now()->addSeconds((int) $response->json('expires_in', 3600)),
        ]);

        return $connection->access_token;
    }
}
