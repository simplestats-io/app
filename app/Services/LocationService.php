<?php

namespace App\Services;

use App\Location\Position;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Locale;
use Stevebauman\Location\Facades\Location;
use Stevebauman\Location\Position as BasePosition;

class LocationService
{
    /**
     * CLDR short names that read worse than the common English name the geo
     * providers (and our existing data) mostly use.
     */
    protected const COUNTRY_NAME_OVERRIDES = [
        'CD' => 'DR Congo',
        'CG' => 'Republic of the Congo',
        'CI' => 'Ivory Coast',
        'HK' => 'Hong Kong',
        'MM' => 'Myanmar',
        'MO' => 'Macao',
        'PS' => 'Palestine',
        'VC' => 'Saint Vincent and the Grenadines',
    ];

    /**
     * In-process memo so the datacenter check and the geo mutator resolve
     * the same IP with a single lookup within one job.
     *
     * @var array<string, BasePosition|false>
     */
    protected array $memo = [];

    public function get(string $ip): BasePosition|false
    {
        if (count($this->memo) > 100) {
            $this->memo = [];
        }

        if (! array_key_exists($ip, $this->memo)) {
            $this->memo[$ip] = $this->resolve($ip);
        }

        return $this->memo[$ip];
    }

    /**
     * Successful lookups are cached for a day to stay well below ip-api's
     * free-tier rate limit (45 req/min). The cache key is a hashed IP that
     * rotates daily (same idea as the visitor hash salt) and the cached value
     * holds no IP, so no plain IP is ever persisted. Failed lookups are not
     * cached, a later job may succeed.
     *
     * A position without the hosting flag means ip-api throttled us and a
     * fallback driver (which has no flag) answered. Caching that for a full day
     * would keep the datacenter filter blind to that IP even after the rate
     * limit frees up, so such results are cached only briefly.
     */
    protected function resolve(string $ip): BasePosition|false
    {
        $key = 'location:'.hash('sha256', $ip.now('UTC')->toDateString());

        if (is_array($cached = Cache::get($key))) {
            return $this->hydrate($cached);
        }

        $position = Location::get($ip);

        if ($position instanceof BasePosition) {
            $hasHostingFlag = $position instanceof Position && $position->isHosting !== null;
            Cache::put($key, $this->dehydrate($position), $hasHostingFlag ? now()->addDay() : now()->addMinutes(15));
        }

        return $position;
    }

    /**
     * @return array{countryName: ?string, countryCode: ?string, regionName: ?string, cityName: ?string, isHosting: ?bool, isProxy: ?bool, asn: ?int}
     */
    protected function dehydrate(BasePosition $position): array
    {
        return [
            'countryName' => $position->countryName,
            'countryCode' => $position->countryCode,
            'regionName' => $position->regionName,
            'cityName' => $position->cityName,
            'isHosting' => $position instanceof Position ? $position->isHosting : null,
            'isProxy' => $position instanceof Position ? $position->isProxy : null,
            'asn' => $position instanceof Position ? $position->asn : null,
        ];
    }

    protected function hydrate(array $cached): Position
    {
        $position = new Position;
        $position->countryName = $cached['countryName'];
        $position->countryCode = $cached['countryCode'];
        $position->regionName = $cached['regionName'];
        $position->cityName = $cached['cityName'];
        $position->isHosting = $cached['isHosting'];
        $position->isProxy = $cached['isProxy'];
        $position->asn = $cached['asn'] ?? null;

        return $position;
    }

    /**
     * Country names are derived from the ISO code instead of taken from the
     * provider: every fallback driver spells them differently ("Netherlands" vs
     * "The Netherlands") and IpInfo returns no name at all, and each spelling
     * became its own country row.
     */
    public function countryName(string $countryCode): string
    {
        $countryCode = Str::upper($countryCode);

        if (isset(self::COUNTRY_NAME_OVERRIDES[$countryCode])) {
            return self::COUNTRY_NAME_OVERRIDES[$countryCode];
        }

        $name = Locale::getDisplayRegion('-'.$countryCode, 'en');

        return str_replace([' & ', 'St. '], [' and ', 'Saint '], $name);
    }
}
