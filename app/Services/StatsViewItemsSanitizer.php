<?php

namespace App\Services;

use App\Helpers\PagePathHelper;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Items are checked one by one instead of with `views.*` validation rules: one broken
 * entry must not reject 999 good ones, and the validator is slow on 1000 nested items.
 */
class StatsViewItemsSanitizer
{
    public const int MAX_VIEWS_PER_ITEM = 1000;

    private const int MAX_AGE_SECONDS = 48 * 3600;

    private const int MAX_FUTURE_SECONDS = 300;

    /**
     * Valid items are merged per visitor and page (views summed, latest time kept).
     *
     * @param  array<mixed>  $views
     * @return array{items: list<array{visitor_hash: string, page: string, views: int, last_viewed_at: int}>, rejected: int}
     */
    public function sanitize(array $views, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now('UTC');
        $oldest = $now->getTimestamp() - self::MAX_AGE_SECONDS;
        $newest = $now->getTimestamp() + self::MAX_FUTURE_SECONDS;

        $items = [];
        $rejected = 0;

        foreach ($views as $view) {
            if (! is_array($view)) {
                $rejected++;

                continue;
            }

            $hash = $view['h'] ?? null;
            $time = $view['t'] ?? null;
            $count = $view['n'] ?? null;
            $page = $this->normalizePage($view['p'] ?? null);

            if (! is_string($hash) || $hash === '' || Str::length($hash) > 64
                || ! is_int($time)
                || ! is_int($count) || $count < 1 || $count > self::MAX_VIEWS_PER_ITEM
                || is_null($page)) {
                $rejected++;

                continue;
            }

            // Late batches are kept: a view belongs to its visitor-day no matter when it arrives.
            $time = max($oldest, min($newest, $time));
            $key = $hash.'|'.$page;

            if (isset($items[$key])) {
                $items[$key]['views'] += $count;
                $items[$key]['last_viewed_at'] = max($items[$key]['last_viewed_at'], $time);
            } else {
                $items[$key] = ['visitor_hash' => $hash, 'page' => $page, 'views' => $count, 'last_viewed_at' => $time];
            }
        }

        return ['items' => array_values($items), 'rejected' => $rejected];
    }

    private function normalizePage(mixed $page): ?string
    {
        if (! is_string($page)) {
            return null;
        }

        $page = Str::before(Str::before($page, '#'), '?');

        if (! Str::startsWith($page, '/')) {
            return null;
        }

        $page = PagePathHelper::normalize($page);

        // Same truncation as the entry page lookup (HasStatsTracking), so both match.
        return is_null($page) ? null : Str::limit($page, 252);
    }
}
