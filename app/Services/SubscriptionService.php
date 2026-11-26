<?php

namespace App\Services;

use App\Enums\SubscriptionInterval;
use App\Models\Team;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Laravel\Paddle\Cashier;

class SubscriptionService
{
    const PLAN_FREE = 'Free';

    const PLAN_STARTER = 'Starter';

    const PLAN_PLUS = 'Plus';

    const PLAN_BUSINESS = 'Business';

    const PLAN_SCALE = 'Scale';

    const PLAN_PREMIUM = 'Premium';

    const PLAN_ULTIMATE = 'Ultimate';

    const PLAN_ENTERPRISE = 'Enterprise';

    const PLAN_SELF_HOSTED = 'Self-Hosted';

    const UNLIMITED_AMOUNT = -1;

    const ALARM_PERCENTAGE = 70;

    public function getFrontendPlans(string $billableType = 'user'): Collection
    {
        $plans = collect(config("spark.billables.$billableType.plans", []));
        $planIds = $plans->flatMap(fn ($item): array => [$item['monthly_id'], $item['yearly_id']])->filter();
        $planPrices = $this->getPlanIdToPrices($planIds);

        $discount = $this->getDiscount();

        return $plans->map(
            //            if (isset($planPrices[$item['monthly_id']])) dd($planPrices[$item['yearly_id']]);
            fn ($item): array => [
                'name' => $item['name'],
                'short_description' => $item['short_description'],
                'price_monthly' => $planPrices[$item['monthly_id']]->totals['subtotal'] ?? 0,
                'price_yearly' => $planPrices[$item['yearly_id']]->totals['subtotal'] ?? 0,
                'tax_mode_monthly' => $planPrices[$item['monthly_id']]->price['tax_mode'] ?? '',
                'tax_mode_yearly' => $planPrices[$item['yearly_id']]->price['tax_mode'] ?? '',
                'yearly_incentive' => $this->calculateYearlyIncentive(
                    $planPrices[$item['monthly_id']]->totals['subtotal'] ?? 0,
                    $planPrices[$item['yearly_id']]->totals['subtotal'] ?? 0,
                ),
                // do not show/expose FQN to FE...
                'features' => collect($item['features'])->mapWithKeys(fn ($item, $key): array => [class_basename($key) => $item]),
                'options' => collect($item['options'])->mapWithKeys(fn ($item, $key): array => [class_basename($key) => $item]),
                'promoted' => $item['promoted'] ?? false,
                'discount' => $discount['percent'] ?? 0,
            ]);
    }

    public function getPlanIdToPrices(Collection $planIds): Collection
    {
        // sometimes when I work on it, I put the ttl in local mode to 0
        $ttl = (app()->isProduction()) ? 86400 : 86400;

        // Keyed by the plan ids: deploys keep the cache, so changed plans must not hit stale prices.
        $cacheKey = 'paddle-prices:'.md5($planIds->sort()->implode(','));

        return Cache::remember($cacheKey, $ttl, fn () => Cashier::previewPrices($planIds, [
            'customer_ip_address' => request()->ip(),
        ])->mapWithKeys(fn ($item): array => [$item->price['id'] => $item]));
    }

    private function calculateYearlyIncentive(int $monthlyPrice, int $yearlyPrice): ?int
    {
        if ($monthlyPrice <= 0 || $yearlyPrice <= 0) {
            return null;
        }

        $annualFromMonthly = $monthlyPrice * 12;
        $savings = (int) round(($annualFromMonthly - $yearlyPrice) / $annualFromMonthly * 100);

        return $savings > 0 ? $savings : null;
    }

    public function intervalForPriceId(?string $priceId): ?SubscriptionInterval
    {
        if (! $priceId) {
            return null;
        }

        foreach (config('spark.billables.user.plans', []) as $plan) {
            if (($plan['monthly_id'] ?? null) === $priceId) {
                return SubscriptionInterval::MONTH;
            }
            if (($plan['yearly_id'] ?? null) === $priceId) {
                return SubscriptionInterval::YEAR;
            }
        }

        return null;
    }

    public function planForPriceId(?string $priceId): ?string
    {
        if (! $priceId) {
            return null;
        }

        foreach (config('spark.billables.user.plans', []) as $plan) {
            if (in_array($priceId, [$plan['monthly_id'] ?? null, $plan['yearly_id'] ?? null], true)) {
                return $plan['name'] ?? null;
            }
        }

        return null;
    }

    /**
     * Single source of truth for the plan event limit. Enforced in TWO places,
     * keep them in sync when changing semantics here:
     * - EnsureWithinEventLimit middleware (API ingest, responds terminal 200)
     * - BaseStatsImport::model() (data imports, throws AuthorizationException)
     *
     * $delta covers events not yet reflected in events_count_monthly, e.g. rows
     * batch-inserted during an import (model observers do not fire there).
     */
    public function hasReachedMonthlyEventLimit(Team $team, int $delta = 0): bool
    {
        $allowedEvents = $team->billingOwner?->sparkPlan()->options['Events'];

        // A team whose owner row is gone entirely has no resolvable plan. Dropping
        // its ingest would silently lose customer data for a dangling reference on
        // our side, so we let it through instead.
        if ($allowedEvents === null || $allowedEvents === self::UNLIMITED_AMOUNT) {
            return false;
        }

        return $team->events_count_monthly + $delta >= $allowedEvents;
    }

    public function getPlanAlarm(Team $team): array
    {
        $teamPlan = $team->billingOwner?->sparkPlan();

        if (! $teamPlan) {
            return [];
        }

        $alarms = [];

        if ($teamPlan->options['Events'] !== self::UNLIMITED_AMOUNT) {
            $currentAmount = $team->events_count_monthly;
            $currentPercent = floor($currentAmount / $teamPlan->options['Events'] * 100);

            // this can only happen if the subscription can't be renewed
            if ($currentPercent > 100) {
                $alarms[0] = [
                    'percent' => $currentPercent,
                    'text' => "Your subscription has been canceled or couldn't be renewed automatically. You're now on the free plan and have reached your monthly events limit.",
                ];
            } elseif ($currentPercent > self::ALARM_PERCENTAGE) {
                $monthlyEventsDescr = strtolower($teamPlan->features['Events']);
                $alarms[] = [
                    'percent' => $currentPercent,
                    'text' => "You've used $currentPercent% of your {$teamPlan->name} plan's {$monthlyEventsDescr}.",
                ];
            }
        }

        return $alarms;
    }

    public function getDiscount(): ?array
    {
        if (app()->environment('demo')) {
            return null;
        }

        $discount = config('spark.discount');

        if (now()->gte(Carbon::parse($discount['duration']['start'])->startOfDay()) && now()->lte(Carbon::parse($discount['duration']['end'])->endOfDay())) {
            return $discount;
        }

        return null;
    }
}
