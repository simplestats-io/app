<?php

use App\Location\Position;
use App\Services\LocationService;
use Stevebauman\Location\Facades\Location;
use Stevebauman\Location\LocationFake;
use Stevebauman\Location\LocationManager;

// Location::fake() cannot be called twice (it would wrap the previous fake),
// so subsequent fakes are swapped in manually.
function swapLocationFake(array $responses): void
{
    Location::swap(new LocationFake(new LocationManager, $responses));
}

test('successful location lookups are served from cache afterwards', function (): void {
    $position = new Position;
    $position->countryName = 'Germany';
    $position->countryCode = 'DE';
    $position->isHosting = true;
    $position->isProxy = false;
    Location::fake(['203.0.113.9' => $position]);

    expect(resolve(LocationService::class)->get('203.0.113.9')->isHosting)->toBeTrue();

    // A fresh service instance (empty memo) with a now-empty fake can only
    // succeed via the cache.
    swapLocationFake([]);
    $cached = (new LocationService)->get('203.0.113.9');

    expect($cached)->toBeInstanceOf(Position::class)
        ->and($cached->isHosting)->toBeTrue()
        ->and($cached->isProxy)->toBeFalse()
        ->and($cached->countryName)->toBe('Germany');
});

test('failed location lookups are not cached', function (): void {
    Location::fake([]);

    expect(resolve(LocationService::class)->get('203.0.113.10'))->toBeFalse();

    $position = new Position;
    $position->isHosting = true;
    swapLocationFake(['203.0.113.10' => $position]);

    expect((new LocationService)->get('203.0.113.10')->isHosting)->toBeTrue();
});

test('the asn survives the cache round trip', function (): void {
    $position = new Position;
    $position->isHosting = true;
    $position->asn = 16509;
    Location::fake(['203.0.113.11' => $position]);

    resolve(LocationService::class)->get('203.0.113.11');

    swapLocationFake([]);

    expect((new LocationService)->get('203.0.113.11')->asn)->toBe(16509);
});

test('positions without a hosting flag are only cached briefly', function (): void {
    // A fallback driver (ip-api throttled) returns geo but no hosting flag.
    // Caching that for a full day would keep the datacenter filter blind, so
    // it must expire quickly.
    Location::fake(['203.0.113.12' => new Position]);

    expect(resolve(LocationService::class)->get('203.0.113.12'))->toBeInstanceOf(Position::class);

    swapLocationFake([]);

    $this->travel(16)->minutes();

    expect((new LocationService)->get('203.0.113.12'))->toBeFalse();
});

test('positions with a hosting flag stay cached beyond the short window', function (): void {
    $position = new Position;
    $position->isHosting = true;
    Location::fake(['203.0.113.13' => $position]);

    resolve(LocationService::class)->get('203.0.113.13');

    swapLocationFake([]);

    $this->travel(16)->minutes();

    expect((new LocationService)->get('203.0.113.13')->isHosting)->toBeTrue();
});

test('country names are derived from the iso code', function (string $code, string $name): void {
    expect(resolve(LocationService::class)->countryName($code))->toBe($name);
})->with([
    'plain cldr name' => ['NL', 'Netherlands'],
    'lowercase code' => ['de', 'Germany'],
    'ampersand spelled out' => ['BA', 'Bosnia and Herzegovina'],
    'override' => ['CD', 'DR Congo'],
]);
