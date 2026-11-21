<?php

namespace App\Services;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Matches referers against Matomo's community referrer spam list (public domain,
 * also used by Plausible). These bots send a clean browser User-Agent from
 * residential proxies, so neither the UA nor the datacenter IP check catches them.
 *
 * The list ships with the app (resources/data) and is refreshed weekly into the
 * local disk by app:update-referrer-spam-list, which takes precedence when present.
 * Both files hold one normalized domain per line, so loading is a plain explode
 * (about 0.1 ms) and needs no cache on this hot ingest path.
 */
class ReferrerSpamService
{
    public const string SOURCE_URL = 'https://raw.githubusercontent.com/matomo-org/referrer-spam-list/master/spammers.txt';

    public const string STORAGE_PATH = 'referrer-spam-list.txt';

    /**
     * A broken list must never replace a working one, since an empty list would
     * silently let all referrer spam through again.
     */
    public const int MIN_DOMAINS = 1000;

    /**
     * How long clients skip a spam referer locally (`skip` hint in the visitor
     * response), so a spam wave stops costing them a queue job and an API call.
     */
    public const int SKIP_TTL = 86400;

    /** @var array<string, true>|null */
    private ?array $domains = null;

    public function isSpam(?string $referer): bool
    {
        return $this->matchedDomain($referer) !== null;
    }

    /**
     * The list entry the referer matches. The list holds registrable domains, spam
     * often arrives from a subdomain, so the host and its parent domains are checked.
     */
    public function matchedDomain(?string $referer): ?string
    {
        if (blank($referer)) {
            return null;
        }

        $referer = Str::lower(trim($referer));
        $host = parse_url($referer, PHP_URL_HOST) ?? parse_url('https://'.$referer, PHP_URL_HOST) ?? '';

        $domains = $this->domains();
        $labels = explode('.', rtrim($host, '.'));

        while (count($labels) >= 2) {
            $candidate = implode('.', $labels);

            if (isset($domains[$candidate])) {
                return $candidate;
            }

            array_shift($labels);
        }

        return null;
    }

    /**
     * @return list<string>
     */
    public static function parseDomains(string $contents): array
    {
        return Str::of($contents)
            ->lower()
            ->explode("\n")
            ->map(fn (string $line): string => trim($line))
            ->filter(fn (string $line): bool => Str::contains($line, '.') && ! Str::contains($line, ['/', ' ', '#']))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return array<string, true>
     */
    private function domains(): array
    {
        if ($this->domains !== null) {
            return $this->domains;
        }

        $stored = $this->load(Storage::disk('local')->path(self::STORAGE_PATH));

        return $this->domains = count($stored) >= self::MIN_DOMAINS
            ? $stored
            : $this->load(resource_path('data/referrer-spam-list.txt'));
    }

    /**
     * @return array<string, true>
     */
    private function load(string $path): array
    {
        $contents = is_file($path) ? trim((string) file_get_contents($path)) : '';

        return $contents === '' ? [] : array_fill_keys(explode("\n", $contents), true);
    }
}
