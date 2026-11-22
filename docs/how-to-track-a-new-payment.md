---
description: "Track payments in Laravel with SimpleStats from your own payment model, or from Cashier, Lemon Squeezy and Polar with one line of config, and attribute revenue to your marketing channels."
---

# How to track a new payment

As the payment model is named very individually, we did not set any default for it. You should give it the name of the model which holds your payments/transactions data.

::: tip
Using **Cashier Stripe**, **Cashier Paddle**, **Cashier Mollie**, **Lemon Squeezy** or **Polar**? Then you need no payment model at all: set the provider with one line of config, see [Payment Providers](payment-providers.md). This page covers everything else: your own payment table, any other provider, or custom logic.
:::

## Configuration

Set the name of your payment model in the config:

```php
//config/simplestats-client.php

    'tracking_types' => [
        // ...
        
        'payment' => [
            'model' => App\Models\Transaction::class,
        ],
        
        // ...
    ],

```

:::info
For this guide lets assume your payment model is called `Transaction`.
:::

## Payment Tracking

To track a new payment, the only thing you have to do is to add the `TrackablePayment` contract to your payment model and then implement the methods defined in the contract.

It may look like this:

```php
class Transaction extends Model implements TrackablePayment
{
    //..
    
    /**
     * The person associated with the payment.
     * This may either be a user or a visitor.
     */
    public function getTrackingPerson(): TrackablePerson
    {
        return User::find($this->user_id);
    }

    /**
     * The time when the payment happened.
     */
    public function getTrackingTime(): CarbonInterface
    {
        return $this->created_at;
    }

    /**
     * The gross amount of the payment in cents ($1 = 100 Cent).
     */
    public function getTrackingGross(): float
    {
        return $this->total;
    }

    /**
     * The net amount of the payment in cents ($1 = 100 Cent).
     */
    public function getTrackingNet(): float
    {
        return $this->total - $this->tax;
    }

    /**
     * The ISO-4217 currency code of the payment.
     * E.g.: EUR, USD, GBP, etc...
     */
    public function getTrackingCurrency(): string
    {
        return $this->currency;
    }

    /**
     * The subscription a recurring payment belongs to (plan and billing
     * interval), or null for a one-time payment.
     */
    public function getTrackingSubscription(): ?TrackingSubscription
    {
        return null;
    }
    
    //..
}
```

Now each time a new **Transaction** model gets created, an API request is sent to _SimpleStats_ to count a new payment.

::: info
You may tweak the defined methods to your needs and apply any logic you need there!
:::

::: danger
Remember that **Gross** and **Net** must return cents!
:::

### Subscriptions

`getTrackingSubscription()` tells _SimpleStats_ whether a payment is part of a subscription, which plan it belongs to and how often it renews. Return a `TrackingSubscription` for subscription payments, or `null` for one-time payments. This is what lets us compute recurring revenue metrics (MRR/ARR) and segment them by plan down the line.

The `TrackingSubscription` value object takes a `plan` (a free-form string, e.g. `Starter`, `Business`, or `null` if you don't track plans) and an `interval` (`SubscriptionInterval::Month` or `SubscriptionInterval::Year`).

```php
use SimpleStatsIo\LaravelClient\Data\TrackingSubscription;
use SimpleStatsIo\LaravelClient\Enums\SubscriptionInterval;

class Transaction extends Model implements TrackablePayment
{
    //..

    public function getTrackingSubscription(): ?TrackingSubscription
    {
        return match ($this->billing_cycle) {
            'monthly' => new TrackingSubscription($this->plan_name, SubscriptionInterval::Month),
            'yearly' => new TrackingSubscription($this->plan_name, SubscriptionInterval::Year),
            default => null, // one-time payment
        };
    }
}
```

The available intervals are `SubscriptionInterval::Month` and `SubscriptionInterval::Year`. The plan is optional, pass `null` if you don't want to track it.

### Payment Tracking for Visitors

If a payment is made from a visitor and not a registered user you can handle this by returning a `Visitor` instead of a `User` object in the `getTrackingPerson` method (this feature is only available since version `3.0` of the _SimpleStats_ [client package](https://github.com/simplestats-io/laravel-client)).

::: tip
You can safely [skip](#with-condition) this section if that's never the case for you.
:::

#### Adding a Visitor Hash to Your Payment Model

When a new visitor visits your site, our package creates a [Visitor Hash](how-to-track-a-new-visitor.md#the-visitor-hash) and exposes it via `SimplestatsClient::getVisitorHash()`. You can use it to associate a payment with a visitor, which allows us to calculate the [ROI](https://en.wikipedia.org/wiki/Return_on_investment) of your marketing campaigns.

We recommend adding a `visitor_hash` column to your payment model. You can do that by publishing the following migration:

```bash
php artisan vendor:publish --tag=simplestats-client-migrations
php artisan migrate
```

Now make sure to add the hash when creating a new payment:

```php
use SimpleStatsIo\LaravelClient\Facades\SimplestatsClient;

Transaction::create([
    ...
    // add the visitor_hash to the models fillable array
    'visitor_hash' => SimplestatsClient::getVisitorHash(),
]);
```

:::info
Sometimes, you might need to create the payment model exclusively within the completed webhook of your payment provider. As you can't use the current session there, you need to make sure to pass the `visitor_hash` as additional metadata to the request. Here are some common payment providers and how you can accomplish that: [Stripe](https://docs.stripe.com/metadata), [Paddle](https://developer.paddle.com/build/transactions/custom-data), [Lemon Squeezy](https://docs.lemonsqueezy.com/help/checkout/passing-custom-data)
:::

Finally, you need to make sure to return a `Visitor` object in your payment model if it's a visitor payment:

```php
use SimpleStatsIo\LaravelClient\Visitor;

class Transaction extends Model implements TrackablePayment
{
    //..
    
    /**
     * The person associated with the payment.
     * This may either be a user or a visitor.
     */
    public function getTrackingPerson(): TrackablePerson
    {
        if (! emtpy($this->visitor_hash)) {
            return new Visitor($this->visitor_hash);
        } else {
            return User::find($this->user_id);
        }
    }
    
    // ...
}
```

::: info
If your application does not have any users at all, just always return a `Visitor` object.
:::

Congrats, now each time a new **Transaction** model gets created, an API request is sent to _SimpleStats_ to count a new payment.

### With Condition

Sometimes you may not want to count a newly created payment immediately as a "valid" one. For such cases, the `TrackablePaymentWithCondition` contract is designed to be utilized. Use it if you want to track/count a new payment only when a certain condition is fulfilled, such as the payment _status_ has switched from "pending" to "completed".

```php
use SimpleStatsIo\LaravelClient\Contracts\TrackablePaymentWithCondition;

class Transaction extends Model implements TrackablePaymentWithCondition
{
    // ...

    /**
     * The condition that should be fulfilled in order to track the payment.
     */
    public function passTrackingCondition(): bool
    {
        return $this->status === 'completed';
    }

    /**
     * The field(s) we should watch for changes to recheck the condition.
     */
    public function watchTrackingFields(): array
    {
        return ['status'];
    }
    
    // ...
}
```

:::info
Now, as soon as the payment _status_ switches from "pending" to "completed", the payment gets tracked and counted. You may tweak these methods completely to your needs!
:::

::: tip
You can check as many fields as you like and apply any logic you want here.
:::

## Laravel Cashier, Paddle & Lemon Squeezy

The quickest way for these packages is a [payment provider](payment-providers.md): one line of config, no code. Implement the contract yourself only if you need custom logic, for example your own plan names or conditions.

Packages like [Cashier Paddle](https://laravel.com/docs/cashier-paddle) and [Lemon Squeezy](https://github.com/lmsqueezy/laravel) ship the payment model, including table, migrations and webhook syncing. You extend the shipped model, implement the contract on your subclass and register it.

For **Cashier Paddle** it looks like this:

```php
<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Laravel\Paddle\Transaction as PaddleTransaction;
use SimpleStatsIo\LaravelClient\Contracts\TrackablePerson;
use SimpleStatsIo\LaravelClient\Contracts\TrackablePaymentWithCondition;
use SimpleStatsIo\LaravelClient\Data\TrackingSubscription;

class Transaction extends PaddleTransaction implements TrackablePaymentWithCondition
{
    public function getTrackingPerson(): TrackablePerson
    {
        return $this->billable;
    }

    public function getTrackingTime(): CarbonInterface
    {
        return $this->created_at;
    }

    public function getTrackingGross(): float
    {
        return $this->total;
    }

    public function getTrackingNet(): float
    {
        return $this->total - $this->tax;
    }

    public function getTrackingCurrency(): string
    {
        return $this->currency;
    }

    public function getTrackingSubscription(): ?TrackingSubscription
    {
        return null; // or build it from your Paddle price ids, see "Subscriptions" above
    }

    public function passTrackingCondition(): bool
    {
        return $this->status === 'completed';
    }

    public function watchTrackingFields(): array
    {
        return ['status'];
    }
}
```

This is the same setup _SimpleStats_ itself uses to track its own payments.

We use the [With Condition](#with-condition) contract here because Paddle creates transactions as "pending" first and marks them "completed" once the payment goes through.

Then tell Cashier Paddle to use your subclass, in your `AppServiceProvider`:

```php
use App\Models\Transaction;
use Laravel\Paddle\Cashier;

public function boot(): void
{
    Cashier::useTransactionModel(Transaction::class);
}
```

And register it as your payment model in the config, as shown in [Configuration](#configuration) above. Done, every completed Paddle transaction now gets tracked automatically.

**Lemon Squeezy** works the same way: extend `LemonSqueezy\Laravel\Order`, implement the contract and register your subclass via `LemonSqueezy::useOrderModel()`.

::: info
**Cashier Stripe** ships no local payment model, individual charges live entirely in Stripe's API. Use the [Cashier Stripe provider](payment-providers.md#cashier-stripe), which reads them from Stripe for you. If you need full control, see [How to track Stripe payments without a payment model](how-to-track-stripe-payments-without-a-model.md) for how to build your own model from Stripe webhooks.
:::

## Multi Currency Support

Upon registering with _SimpleStats_, you'll be prompted to select a default currency for your team/company. For services, shops, or applications that operate across multiple currencies, **our API** seamlessly handles the conversion. Any transactions made in foreign currencies are automatically **converted** to your team's default currency, using a **daily exchange rate**. This feature ensures that all financial data is standardized and accurately reflects in your analytics, simplifying the management of international transactions.

::: info
_Multi Currency Support_ is not available on the free subscription plan.
:::
