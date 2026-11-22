---
title: "How to Track Laravel Cashier Payments with SimpleStats"
slug: "how-to-track-laravel-cashier-payments"
date: "2026-04-10"
excerpt: "One of the most common questions we get is how to integrate SimpleStats with Laravel Cashier. The good news: SimpleStats is payment-provider agnostic. Whether you use Stripe, Paddle, or Lemon Squeezy, the pattern is always the same. Implement a contract on your payment model, point to it in your config, and you're done."
tags:
  - Laravel
  - Stripe
  - Paddle
  - Payments
published: true
author_id: 1
---

One of the most common questions we get is: *"How does SimpleStats work with Laravel Cashier?"*

The good news: SimpleStats is payment-provider agnostic. You implement a contract on your payment model, register it in your config, and SimpleStats takes care of the rest.

The only thing that differs between providers is where the model comes from. **Paddle** ships an Eloquent model you can extend directly. **Stripe** doesn't, so you need to create your own. This guide walks you through both, starting with the easier one.

## The Pattern

No matter which payment provider you use, the integration always follows the same two steps:

1. Implement the `TrackablePayment` contract on your payment model
2. Register that model in your `simplestats-client.php` config

The contract defines six methods:

```php
interface TrackablePayment
{
    public function getTrackingPerson(): TrackablePerson;
    public function getTrackingTime(): CarbonInterface;
    public function getTrackingGross(): float;
    public function getTrackingNet(): float;
    public function getTrackingCurrency(): string;
    public function getTrackingSubscription(): ?TrackingSubscription;
}
```

- **`getTrackingPerson()`** returns the user (or visitor) associated with the payment
- **`getTrackingTime()`** returns when the payment happened
- **`getTrackingGross()`** returns the gross amount in **cents** ($1 = 100)
- **`getTrackingNet()`** returns the net amount in **cents**
- **`getTrackingCurrency()`** returns the ISO-4217 currency code (EUR, USD, GBP, etc.)
- **`getTrackingSubscription()`** returns a `TrackingSubscription` (plan + billing interval) for recurring payments, or `null` for one-time payments. If you only take one-time payments, just return `null`. We cover recurring subscriptions in detail [further down](#tracking-subscriptions).

Once your model implements this contract, register it in your config:

```php
// config/simplestats-client.php

'tracking_types' => [
    // ...

    'payment' => [
        'model' => App\Models\Transaction::class,
    ],

    // ...
],
```

That's it. Every time a new instance of that model gets created, SimpleStats automatically tracks the payment. Now let's look at how this works with each provider.

---

## Cashier Paddle

Paddle is the easiest integration because Cashier Paddle ships with a `Transaction` Eloquent model that you can extend directly.

### Step 1: Create Your Transaction Model

Create a new model that extends Paddle's `Transaction` and implements the `TrackablePaymentWithCondition` contract:

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
        return null; // one-time payment, see "Tracking Subscriptions" below
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

We use `TrackablePaymentWithCondition` here instead of the simpler `TrackablePayment` because Paddle transactions start as "pending" and only become "completed" after the payment goes through. The `passTrackingCondition()` method ensures we only track completed payments, and `watchTrackingFields()` tells SimpleStats to re-check the condition whenever the `status` field changes.

### Step 2: Register the Model

Tell Cashier Paddle to use your extended model. In your `AppServiceProvider`:

```php
use App\Models\Transaction;
use Laravel\Paddle\Cashier;

public function boot(): void
{
    Cashier::useTransactionModel(Transaction::class);
}
```

Done. Every completed Paddle transaction now gets tracked automatically.

By the way, this is the same setup we use ourselves: SimpleStats runs on Cashier Paddle and tracks its own payments this way. We like our own dogfood.

> **Using Lemon Squeezy?** The same approach works. The `lmsqueezy/laravel` package ships with an `Order` model you can extend the same way. Just extend `LemonSqueezy\Laravel\Order`, implement the contract, and register it via `LemonSqueezy::useOrderModel()`.

---

## Cashier Stripe

Stripe requires a bit more work. Unlike Paddle and Lemon Squeezy, Cashier Stripe does **not** ship with an Eloquent model for individual payments. It stores subscriptions locally (in the `subscriptions` table), but individual charges, invoices, and checkout payments live entirely in Stripe's API. The `Laravel\Cashier\Payment` class you might have seen in the docs is just a wrapper around Stripe's PaymentIntent object, not an Eloquent model.

If your app already records payments in a table of its own (for invoices, order history, etc.), you're in luck: just implement the contract on that model as shown above, and you're done. The rest of this section is for apps that keep no local payment records at all. In that case, you need to create your own payment model and populate it from Stripe webhooks.

### Understanding Stripe's Webhook Events

Before we dive into code, there's an important detail about Stripe's webhooks: **they overlap**. When a subscription renews, Stripe fires both `invoice.payment_succeeded` and `charge.succeeded`. When a Checkout session completes, it fires `checkout.session.completed` and `charge.succeeded`. If you listen to multiple events, you'll end up with duplicate payments in your database.

The cleanest solution: listen to **`charge.succeeded` only**. Every successful payment in Stripe (subscriptions, one-time charges via `$user->charge()`, invoice-based charges via `$user->invoicePrice()`, Checkout sessions, Payment Intents) creates exactly one Charge. One event, complete coverage, no duplicates.

### Step 1: Create the Migration

```bash
php artisan make:migration create_payments_table
```

```php
public function up(): void
{
    Schema::create('payments', function (Blueprint $table) {
        $table->id();
        $table->foreignId('user_id')->constrained()->cascadeOnDelete();
        $table->string('stripe_charge_id')->unique();
        $table->unsignedBigInteger('amount');
        $table->unsignedBigInteger('tax')->default(0);
        $table->string('currency', 3);
        $table->timestamps();
    });
}
```

### Step 2: Create the Payment Model

```php
<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use SimpleStatsIo\LaravelClient\Contracts\TrackablePerson;
use SimpleStatsIo\LaravelClient\Contracts\TrackablePayment;
use SimpleStatsIo\LaravelClient\Data\TrackingSubscription;

class Payment extends Model implements TrackablePayment
{
    protected $fillable = [
        'user_id',
        'stripe_charge_id',
        'amount',
        'tax',
        'currency',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function getTrackingPerson(): TrackablePerson
    {
        return $this->user;
    }

    public function getTrackingTime(): CarbonInterface
    {
        return $this->created_at;
    }

    public function getTrackingGross(): float
    {
        return $this->amount;
    }

    public function getTrackingNet(): float
    {
        return $this->amount - $this->tax;
    }

    public function getTrackingCurrency(): string
    {
        return strtoupper($this->currency);
    }

    public function getTrackingSubscription(): ?TrackingSubscription
    {
        return null; // one-time payment, see "Tracking Subscriptions" below
    }
}
```

Notice we use `TrackablePayment` here (without condition) instead of `TrackablePaymentWithCondition`. Since we only create the model when the `charge.succeeded` webhook fires, the payment is already confirmed at that point. No need to watch for status changes.

### Step 3: Create the Webhook Listener

Create a listener that reacts to Cashier's `WebhookReceived` event:

```php
<?php

namespace App\Listeners;

use App\Models\Payment;
use App\Models\User;
use Laravel\Cashier\Events\WebhookReceived;

class StripeChargeSucceeded
{
    public function handle(WebhookReceived $event): void
    {
        if ($event->payload['type'] !== 'charge.succeeded') {
            return;
        }

        $charge = $event->payload['data']['object'];

        $user = User::where('stripe_id', $charge['customer'])->first();

        if (! $user) {
            return;
        }

        Payment::updateOrCreate(
            ['stripe_charge_id' => $charge['id']],
            [
                'user_id' => $user->id,
                'amount' => $charge['amount'],
                'tax' => 0,
                'currency' => $charge['currency'],
            ]
        );
    }
}
```

The listener maps the Stripe customer back to your local user via the `stripe_id` column that Cashier adds to your `users` table.

> **Tax handling note:** Stripe's `charge.succeeded` payload doesn't include a separate tax field, so the listener stores a tax of `0` and the net equals the gross. That is a perfectly valid starting point. For charges that belong to an invoice, the subscription section below also reads the tax from the invoice.

### Step 4: Register the Listener

In your `EventServiceProvider`:

```php
use App\Listeners\StripeChargeSucceeded;
use Laravel\Cashier\Events\WebhookReceived;

protected $listen = [
    WebhookReceived::class => [
        StripeChargeSucceeded::class,
    ],
];
```

### Step 5: Enable the Webhook in Stripe

Cashier automatically registers the `/stripe/webhook` route, but you need to make sure `charge.succeeded` is enabled in your Stripe Dashboard under Developers > Webhooks.

Now every Stripe payment (subscriptions, one-time charges, Checkout sessions, everything) gets tracked through SimpleStats.

---

## Tracking Subscriptions

Everything above tracks one-time charges. If you run a subscription business, you also want SimpleStats to know which payments are recurring and on what interval, because that is what powers your **MRR, ARR, churn, and retention**.

The key thing to understand: **in SimpleStats a subscription is not a separate object, it is an attribute of a payment.** That is what the sixth contract method is for:

```php
public function getTrackingSubscription(): ?TrackingSubscription;
```

Return a `TrackingSubscription` (plan + billing interval) for recurring payments, or `null` for one-time payments. Because the subscription rides along on the payment, you keep tracking on the exact same `charge.succeeded` event. Every renewal is a new Charge in Stripe, so every renewal is a new payment that carries its interval. That means one event still covers everything: signups, renewals, and one-time purchases.

### Why not `customer.subscription.updated`?

A natural instinct is to move subscription tracking onto `customer.subscription.updated`, since that event fires when a subscription is created and again on each renewal. It's the wrong trigger here, for two reasons:

- **It carries no money.** `subscription.updated` has no charge and no amount attached, so there is nothing concrete to record as a payment. SimpleStats is payment-centric, it counts payments and reads the subscription off them, not the other way around.
- **It fires for lots of non-billing reasons:** plan changes, quantity changes, a scheduled cancellation, a trial ending, a new default card. Creating a payment on each of those would produce spurious and duplicate entries.

So keep `charge.succeeded` as your single trigger. The only extra work is determining the interval and plan for that charge.

### Read the interval from the charge, not from the user

A tempting shortcut is to look at the user's current subscription, for example `$user->onPrice(config('custom.stripe.price_monthly'))`. That works for renewals, but it has a race on the very first payment: `charge.succeeded` can arrive before Cashier has finished syncing the brand-new subscription into your local tables, so `onPrice()` may still be empty or point at the previous plan.

The reliable source of truth is the charge itself. A subscription charge always belongs to an invoice, and the invoice line references the price that was billed. Read the interval and plan straight from there, so it never depends on sync timing.

First, add two nullable columns to your payments table:

```php
Schema::table('payments', function (Blueprint $table) {
    $table->string('billing_interval')->nullable();
    $table->string('plan')->nullable();
});
```

Then populate them in the listener from Step 3, right before `updateOrCreate`:

```php
use Laravel\Cashier\Cashier;

// ... existing $charge and $user lookup ...

$billingInterval = null;
$plan = null;
$tax = 0;

if ($charge['invoice']) {
    $invoice = Cashier::stripe()->invoices->retrieve(
        $charge['invoice'],
        ['expand' => ['lines.data.price']]
    );

    $tax = $invoice->total_excluding_tax !== null
        ? $invoice->total - $invoice->total_excluding_tax
        : 0;

    $priceId = $invoice->lines->data[0]->price->id ?? null;

    [$billingInterval, $plan] = match ($priceId) {
        config('custom.stripe.price_monthly') => ['month', 'pro'],
        config('custom.stripe.price_annually') => ['year', 'pro'],
        default => [null, null],
    };
}

Payment::updateOrCreate(
    ['stripe_charge_id' => $charge['id']],
    [
        'user_id' => $user->id,
        'amount' => $charge['amount'],
        'tax' => $tax,
        'currency' => $charge['currency'],
        'billing_interval' => $billingInterval,
        'plan' => $plan,
    ]
);
```

Finally, implement `getTrackingSubscription()` on the Payment model from the stored columns:

```php
use SimpleStatsIo\LaravelClient\Data\TrackingSubscription;
use SimpleStatsIo\LaravelClient\Enums\SubscriptionInterval;

public function getTrackingSubscription(): ?TrackingSubscription
{
    return match ($this->billing_interval) {
        'month' => new TrackingSubscription($this->plan, SubscriptionInterval::Month),
        'year' => new TrackingSubscription($this->plan, SubscriptionInterval::Year),
        default => null, // one-time payment
    };
}
```

The `plan` is a free-form string (`pro`, `team`, `enterprise`), used to segment your recurring metrics by plan later. Pass `null` if you don't track plans. The `interval` (`Month` or `Year`) is what lets SimpleStats normalize every subscription into a monthly figure: a €120/year plan and a €10/month plan both contribute €10 to MRR.

> **Stripe API versions:** the shape of invoice line items has changed across Stripe API versions (`price`, `plan`, or `pricing`). Check the payload your pinned Cashier/Stripe API version actually sends and adjust the `$invoice->lines->data[0]->price->id` path accordingly.

### Paddle and Lemon Squeezy

Same idea, less plumbing. Their transaction/order models already carry the billing details locally, so you can implement `getTrackingSubscription()` directly on the model (matching on the price or billing period you already store) without an extra API call.

---

## When to Use Conditional Tracking

In the Paddle and Lemon Squeezy examples above, we used `TrackablePaymentWithCondition` instead of the simpler `TrackablePayment`. This is useful whenever your payment model gets created before the payment is actually confirmed.

For example, Paddle creates a transaction record immediately, then updates its status to "completed" once the payment goes through. Without the condition, SimpleStats would track the payment the moment the record is created, even if it's still pending.

The `TrackablePaymentWithCondition` contract adds two methods:

```php
public function passTrackingCondition(): bool
{
    return $this->status === 'completed';
}

public function watchTrackingFields(): array
{
    return ['status'];
}
```

`watchTrackingFields()` tells SimpleStats which fields to monitor. When any of those fields change, SimpleStats re-evaluates the condition. Once `passTrackingCondition()` returns `true`, the payment gets tracked.

You can use any logic you want here. Check multiple fields, combine conditions, whatever fits your business logic.

---

## Works with Any Provider

While this guide focuses on Laravel Cashier (Stripe and Paddle), the pattern works with any payment provider. If you use a different gateway, the approach is the same: create or extend a model, implement the contract, register it in the config.

For the full payment tracking documentation, including the Cashier Paddle and Lemon Squeezy setup, visitor-based payment tracking for guest checkouts and multi-currency support, check out our [payment tracking guide](/docs/how-to-track-a-new-payment.html). The Stripe webhook setup is also documented in [How to track Stripe payments without a payment model](/docs/how-to-track-stripe-payments-without-a-model.html).
