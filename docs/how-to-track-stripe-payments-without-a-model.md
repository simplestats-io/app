---
description: "Track Laravel Cashier Stripe payments in SimpleStats even if your app stores no payment model, using the Stripe webhook events."
---

# How to track Stripe payments without a payment model

Tracking payments always works through an Eloquent model implementing the `TrackablePayment` contract, as described in [How to track a new payment](how-to-track-a-new-payment.md). With **Cashier Stripe** there is one special situation: unlike Paddle or Lemon Squeezy, Cashier Stripe does **not** ship an Eloquent model for individual payments. It stores subscriptions locally (in the `subscriptions` table), but individual charges, invoices, and checkout payments live entirely in Stripe's API. The `Laravel\Cashier\Payment` class is just a wrapper around Stripe's PaymentIntent object, not an Eloquent model.

::: tip
If your app already records payments in an own table (for invoices, order history, etc.), you don't need this guide. Just implement the contract on that model as usual.
:::

If your app has no local payment records at all, you need to create a payment model and populate it from Stripe webhooks. This guide walks you through it.

::: info
Why a model and not a direct API call? _SimpleStats_ uses your model's primary key as the payment's stable ID. That is what deduplicates retried webhooks and enables [updating a payment later](how-to-update-a-payment.md) (amount corrections, refunds). If you really want to send raw requests yourself, see the [Ingest API](ingest-api.md), but then deduplication and updates are on you.
:::

## Understanding Stripe's Webhook Events

Stripe's webhook events **overlap**. When a subscription renews, Stripe fires both `invoice.payment_succeeded` and `charge.succeeded`. When a Checkout session completes, it fires `checkout.session.completed` and `charge.succeeded`. If you listen to multiple events, you will end up with duplicate payments in your database.

The cleanest solution: listen to **`charge.succeeded` only**. Every successful payment in Stripe (subscriptions, one-time charges via `$user->charge()`, invoice-based charges via `$user->invoicePrice()`, Checkout sessions, Payment Intents) creates exactly one Charge. One event, complete coverage, no duplicates.

## Step 1: Create the Migration

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

## Step 2: Create the Payment Model

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

Notice we use `TrackablePayment` (without condition) instead of `TrackablePaymentWithCondition`. Since we only create the model when the `charge.succeeded` webhook fires, the payment is already confirmed at that point. No need to watch for status changes.

Register the model in your `simplestats-client.php` config:

```php
//config/simplestats-client.php

    'tracking_types' => [
        // ...

        'payment' => [
            'model' => App\Models\Payment::class,
        ],

        // ...
    ],
```

## Step 3: Create the Webhook Listener

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

The listener maps the Stripe customer back to your local user via the `stripe_id` column that Cashier adds to your `users` table. The unique `stripe_charge_id` together with `updateOrCreate` makes retried webhooks harmless.

::: info
Stripe's `charge.succeeded` payload does not include a separate tax field, so the listener stores a tax of `0` and the net equals the gross. That is a perfectly valid starting point. For charges that belong to an invoice, the subscription section below also reads the tax from the invoice.
:::

## Step 4: Register the Listener

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

## Step 5: Enable the Webhook in Stripe

Cashier automatically registers the `/stripe/webhook` route, but you need to make sure `charge.succeeded` is enabled in your Stripe Dashboard under Developers > Webhooks.

Now every Stripe payment (subscriptions, one-time charges, Checkout sessions) gets tracked through _SimpleStats_.

## Tracking Subscriptions

Everything above tracks one-time charges. If you run a subscription business, you also want _SimpleStats_ to know which payments are recurring and on what interval, because that is what powers your **MRR, ARR, churn, and retention**.

In _SimpleStats_ a subscription is not a separate object, it is an attribute of a payment (see the [Subscriptions section](how-to-track-a-new-payment.md#subscriptions) of the payment tracking guide). Return a `TrackingSubscription` (plan + billing interval) from `getTrackingSubscription()` for recurring payments, or `null` for one-time payments.

Because the subscription rides along on the payment, you keep tracking on the exact same `charge.succeeded` event. Every renewal is a new Charge in Stripe, so every renewal is a new payment that carries its interval. One event still covers everything: signups, renewals, and one-time purchases.

### Why not `customer.subscription.updated`?

A natural instinct is to move subscription tracking onto `customer.subscription.updated`, since that event fires when a subscription is created and again on each renewal. It is the wrong trigger here, for two reasons:

- **It carries no money.** `subscription.updated` has no charge and no amount attached, so there is nothing concrete to record as a payment. _SimpleStats_ is payment-centric, it counts payments and reads the subscription off them, not the other way around.
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

The `plan` is a free-form string (`pro`, `team`, `enterprise`), used to segment your recurring metrics by plan later. Pass `null` if you don't track plans. The `interval` (`Month` or `Year`) is what lets _SimpleStats_ normalize every subscription into a monthly figure: a €120/year plan and a €10/month plan both contribute €10 to MRR.

::: info
**Stripe API versions:** the shape of invoice line items has changed across Stripe API versions (`price`, `plan`, or `pricing`). Check the payload your pinned Cashier/Stripe API version actually sends and adjust the `$invoice->lines->data[0]->price->id` path accordingly.
:::
