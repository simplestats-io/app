<?php

namespace App\Services;

use App\Cache\DeviceDetectorCache;
use App\Location\Position;
use DeviceDetector\DeviceDetector;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Throwable;

class BotDetectionService
{
    /**
     * Autonomous systems flagged as hosting that carry real people, so their
     * traffic is kept even though the IP is a datacenter IP:
     * - consumer VPN exits (NordVPN, ExpressVPN, ...): M247 and Datacamp/CDN77
     *   host the vast majority of them.
     * - iCloud Private Relay and WARP egress: Apple publishes its egress ranges
     *   on Akamai, Fastly and Cloudflare, and ip-api flags Akamai/Fastly as hosting.
     * Every other datacenter AS (AWS, GCP, Hetzner, Linode, ...) is filtered.
     */
    /**
     * Bump when detectBot() changes, so cached results are not reused.
     */
    private const int RULES_VERSION = 1;

    private const array CONSUMER_EXIT_ASNS = [
        9009,   // M247
        60068,  // Datacamp / CDN77
        212238, // Datacamp (NordVPN exits)
        36183,  // Akamai (Private Relay)
        20940,  // Akamai International (Private Relay)
        54113,  // Fastly (Private Relay)
        13335,  // Cloudflare (Private Relay, WARP)
    ];

    /**
     * Datacenter/hosting IPs (AWS, GCP, Hetzner, ...) are practically always
     * bots faking a real browser User-Agent, so they are dropped. The only
     * exception are the networks that host consumer VPN and Private Relay exits,
     * which carry real people (same approach as Plausible/Fathom). Fails open: unknown
     * hosting flags (fallback drivers, throttled lookups, failed lookups)
     * never drop a visitor.
     */
    public static function isDatacenterIp(?string $ip): bool
    {
        if (! config('location.datacenter_ip_filter')) {
            return false;
        }

        if ($ip === null || filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }

        $position = resolve(LocationService::class)->get($ip);

        if (! $position instanceof Position || $position->isHosting === null) {
            if ($position instanceof Position) {
                self::recordFilterOutcome('null_flags_passed');
            }

            return false;
        }

        if ($position->isHosting !== true) {
            return false;
        }

        if (in_array($position->asn, self::CONSUMER_EXIT_ASNS, true)) {
            self::recordFilterOutcome('vpn_passed');

            return false;
        }

        self::recordFilterOutcome('dropped');

        return true;
    }

    /**
     * Daily counters (no IPs stored) so the filter yield can be measured
     * instead of estimated. `dropped` is the real yield, `null_flags_passed`
     * exposes how often ip-api throttling forces a fail-open (justifies the
     * pro key), `vpn_passed` is the blast radius of the consumer exit exception.
     */
    protected static function recordFilterOutcome(string $outcome): void
    {
        $key = 'datacenter_filter:'.$outcome.':'.now('UTC')->toDateString();

        Cache::add($key, 0, now()->addDays(30));
        Cache::increment($key);
    }

    /**
     * Results are cached per User-Agent: a full device-detector parse costs about
     * 15 ms per request under PHP-FPM, while real traffic shares few distinct UAs.
     * The detector version and RULES_VERSION are part of the key, so an update of
     * either re-evaluates every UA. One day keeps attacker-chosen UAs from piling up
     * in a file store. Fails open to the uncached parse when the store is unavailable.
     */
    public static function isBot(?string $userAgent): bool
    {
        if (! is_string($userAgent) || $userAgent === '') {
            return true;
        }

        $key = 'is_bot:'.DeviceDetector::VERSION.':'.self::RULES_VERSION.':'.hash('xxh128', $userAgent);

        try {
            $cached = Cache::get($key);
        } catch (Throwable) {
            $cached = null;
        }

        if (is_bool($cached)) {
            return $cached;
        }

        $isBot = self::detectBot($userAgent);

        try {
            Cache::put($key, $isBot, now()->addDay());
        } catch (Throwable) {
            // fail open, the next request parses again
        }

        return $isBot;
    }

    private static function detectBot(string $userAgent): bool
    {
        $detector = new DeviceDetector($userAgent);
        $detector->discardBotInformation();
        $detector->setCache(new DeviceDetectorCache);
        $detector->parse();

        if ($detector->isBot()) {
            return true;
        }

        // device-detector treats headless browsers as regular browsers, but they
        // are practically always automation, so match the User-Agent token
        // directly. Mirrors the client package's CheckTracking::isBot() so the
        // filter also protects customers who have not updated the package yet.
        if (Str::contains($userAgent, 'headless', ignoreCase: true)) {
            return true;
        }

        // Real visitors come from browsers or mobile apps. Treat HTTP libraries
        // (curl, python-requests, Go-http-client, ...), feed readers, media
        // players, and PIM clients (email apps) as bot-like, as they almost
        // always represent automated/scripted traffic in a web analytics context.
        return ! $detector->isBrowser() && ! $detector->isMobileApp();
    }
}
