<?php

namespace App\Providers;

use App\Models\Transaction;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\ServiceProvider;
use Laravel\Paddle\Cashier;
use Spark\Spark;

class SparkServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if (config('app.app_type') !== 'saas') {
            return;
        }

        Cashier::useTransactionModel(Transaction::class);

        // Paddle retries a failed renewal for up to 30 days and cancels the subscription
        // afterwards. Without this, the customer drops to the free plan on the first failed
        // attempt and loses every event above the free limit while the retries still run.
        Cashier::keepPastDueSubscriptionsActive();

        Spark::billable(User::class)->resolve(fn (Request $request) => $request->user());

        Spark::billable(User::class)->authorize(fn (User $billable, Request $request): bool => $request->user() &&
               $request->user()->id == $billable->id);
    }
}
