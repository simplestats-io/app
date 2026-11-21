<?php

use App\Services\BotDetectionService;
use DeviceDetector\DeviceDetector;
use Illuminate\Contracts\Cache\Store;
use Illuminate\Support\Facades\Cache;

function deviceDetectorBotCacheKey(): string
{
    return preg_replace('/([^a-z0-9_-]+)/i', '', 'DeviceDetector-'.DeviceDetector::VERSION.'regexes-bot');
}

$realBrowser = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.4 Safari/605.1.15';

it('caches the parsed device-detection regexes across calls', function () use ($realBrowser): void {
    config(['cache.default' => 'array']);
    Cache::store('array')->flush();

    expect(Cache::has(deviceDetectorBotCacheKey()))->toBeFalse();

    BotDetectionService::isBot($realBrowser);

    expect(Cache::has(deviceDetectorBotCacheKey()))->toBeTrue();
});

it('still detects bots correctly when the cache store fails', function () use ($realBrowser): void {
    Cache::extend('throwing', fn () => Cache::repository(new class implements Store
    {
        public function get($key): void
        {
            throw new RuntimeException('cache down');
        }

        public function many(array $keys): void
        {
            throw new RuntimeException('cache down');
        }

        public function put($key, $value, $seconds): void
        {
            throw new RuntimeException('cache down');
        }

        public function putMany(array $values, $seconds): void
        {
            throw new RuntimeException('cache down');
        }

        public function increment($key, $value = 1): void
        {
            throw new RuntimeException('cache down');
        }

        public function decrement($key, $value = 1): void
        {
            throw new RuntimeException('cache down');
        }

        public function forever($key, $value): void
        {
            throw new RuntimeException('cache down');
        }

        public function forget($key): void
        {
            throw new RuntimeException('cache down');
        }

        public function flush(): void
        {
            throw new RuntimeException('cache down');
        }

        public function getPrefix()
        {
            return '';
        }
    }));

    config(['cache.stores.throwing' => ['driver' => 'throwing'], 'cache.default' => 'throwing']);

    expect(BotDetectionService::isBot('Googlebot/2.1 (+http://www.google.com/bot.html)'))->toBeTrue();
    expect(BotDetectionService::isBot($realBrowser))->toBeFalse();
});

it('caches the result per user agent', function () use ($realBrowser): void {
    config(['cache.default' => 'array']);
    Cache::store('array')->flush();

    expect(BotDetectionService::isBot($realBrowser))->toBeFalse();

    $key = collect(Cache::store('array')->getStore()->all())->keys()->first(fn (string $key): bool => str_starts_with($key, 'is_bot:'));

    expect($key)->not->toBeNull();

    Cache::put($key, true, 60);

    expect(BotDetectionService::isBot($realBrowser))->toBeTrue();
});
