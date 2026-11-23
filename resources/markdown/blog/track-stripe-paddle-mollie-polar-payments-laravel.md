---
title: "Track Stripe, Paddle, Mollie and Polar Payments in Laravel with One Line of Config"
slug: "track-stripe-paddle-mollie-polar-payments-laravel"
date: "2026-11-23"
excerpt: "Using Laravel Cashier or Polar? SimpleStats now tracks your payments, refunds and subscriptions out of the box. Set your payment provider in the config, and every sale is attributed to the channel and campaign that brought the customer in."
tags:
  - Laravel
  - Payments
  - Stripe
  - Paddle
  - Product Update
published: true
author_id: 1
---

Revenue is the number that tells you which marketing actually works. To see it in SimpleStats, you had to connect your payments first: implement a contract on your payment model, and with Cashier Stripe even build that model yourself from webhooks.

**That is no longer necessary.** Since version 6.2 of the Laravel client, SimpleStats supports the most common payment packages out of the box. You set your provider in the config, and that's it:

```php
// config/simplestats-client.php

use SimpleStatsIo\LaravelClient\PaymentProviders\CashierStripe;

'tracking_types' => [
    'payment' => [
        'provider' => CashierStripe::class,
        'model' => null,
    ],
],
```

No migration, no payment model, no webhook listener.

---

## Supported Providers

| Package | Provider class |
|---|---|
| [Laravel Cashier (Stripe)](https://laravel.com/docs/billing) | `CashierStripe` |
| [Laravel Cashier (Paddle)](https://laravel.com/docs/cashier-paddle) | `CashierPaddle` |
| [Cashier Mollie](https://github.com/mollie/laravel-cashier-mollie) | `CashierMollie` (beta) |
| [Laravel Polar](https://github.com/danestves/laravel-polar) | `Polar` (beta) |

Mollie and Polar are marked as beta: they are covered by automated tests, but we have not run them against a live account yet. If something looks off, [let us know](https://github.com/simplestats-io/laravel-client/issues).

---

## What Gets Tracked

- **Every completed payment,** one time purchases as well as subscription renewals. Pending, failed or uncaptured payments are skipped.
- **Refunds.** A partial refund lowers the payment to what is left. A full refund removes it, so it no longer counts as revenue, paying user or subscriber. With Stripe, a lost dispute counts as a refund too.
- **Gross and net.** Net is the amount without tax, read from the invoice.
- **Subscriptions.** Monthly and yearly plans come with their interval and plan name, which powers your MRR and ARR.
- **Any currency.** Payments in a foreign currency are converted to your team's currency with the daily exchange rate.

And because every payment belongs to a user, SimpleStats attributes it to the source, campaign and landing page that user came from. You see which channel brings in money, not just clicks.

---

## Setup in Three Steps

### 1. Update the client

```bash
composer require simplestats-io/laravel-client:^6.2
```

Your user model needs to implement `TrackablePerson`, as described in [How to track a new user](/docs/how-to-track-a-new-user.html). That is what links a payment to the user and to the channel they came from.

### 2. Set your provider

Set `provider` in `config/simplestats-client.php` to the class of your package, as shown above, and leave `model` empty.

### 3. Enable the webhook events

The providers listen to the webhooks your payment package already receives. Some of them need events that are not enabled by default. For Cashier Stripe these are the `charge.*` events, for Cashier Paddle the `adjustment.*` events that carry refunds. The [Payment Providers docs](/docs/payment-providers.html) list the events for each provider.

Then check your setup:

```bash
php artisan simplestats:status
```

It shows the active provider and warns you when its package is missing.

---

## Already Tracking Payments with Your Own Model?

You can switch. Set `provider`, set `model` to `null` and remove the contract from your model. Payments you tracked before stay as they are.

If your model extended the package's model, for example `Laravel\Paddle\Transaction`, the provider uses the same payment ids and the switch is seamless. For Cashier Paddle it is also worth it: Paddle books refunds as adjustments, which never reach the `Transaction` model. The provider tracks them.

---

## Your Provider Is Not Listed?

Your own payment model still works with any provider and any custom setup. Implement the `TrackablePayment` contract on the model that holds your payments, see [How to track a new payment](/docs/how-to-track-a-new-payment.html).

Or write a provider yourself: any class implementing the `PaymentProvider` interface can be set as `provider`. The client is open source, and we are happy to receive a pull request for a provider others can use too.

---

Ready to see which of your channels actually pays off? Update the client, set your provider, and your revenue shows up in your dashboard with the next sale. All details are in the [Payment Providers docs](/docs/payment-providers.html).
