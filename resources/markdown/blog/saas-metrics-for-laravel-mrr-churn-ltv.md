---
title: "SaaS Metrics for Laravel: MRR, Churn, NRR and LTV"
slug: "saas-metrics-for-laravel-mrr-churn-ltv"
date: "2026-07-02"
excerpt: "Running a subscription business on Laravel usually means bolting a subscription-analytics tool onto Stripe just to see your MRR. But those tools have no idea where your customers came from. Here's how to get MRR, churn, NRR and LTV in the same place as your acquisition data."
tags:
  - Laravel
  - SaaS
  - MRR
  - Subscriptions
published: true
author_id: 1
---

If you run a subscription business on Laravel, your metrics probably live in three places that don't talk to each other. Stripe or Paddle shows you charges. A tool like ChartMogul, Baremetrics, or ProfitWell turns those charges into MRR and churn. And your web analytics shows you traffic. Three dashboards, three logins, three versions of the truth.

Worse, the subscription-analytics tool in the middle has a blind spot it will never close: **it has no idea where your customers came from.** It can tell you your MRR is growing. It can't tell you which marketing channel is growing it.

SimpleStats brings the whole subscription-metrics stack into the same place as your acquisition data, tracked server-side from your Laravel app. Here's what that stack looks like.

---

## The Metrics

### MRR and ARR

**Monthly Recurring Revenue** is the heartbeat of any subscription business: the normalized monthly value of all your active subscriptions right now. **Annual Recurring Revenue** is simply MRR times twelve.

The important detail is that MRR is a **snapshot, not a sum.** It's the recurring revenue you have *as of today*, not the revenue you collected over a period. When a subscriber churns, MRR drops the moment their subscription lapses, with no payment event required to tell you so. That's why you can't derive it by adding up this month's charges, and why a proper subscription metric has to model the active base directly.

### MRR Movements

A single MRR number going up feels good but hides what's happening underneath. **MRR movements** break the change between two points in time into its components:

- **New**: MRR from brand-new subscribers
- **Expansion**: existing customers upgrading to a higher plan
- **Contraction**: existing customers downgrading
- **Churn**: subscribers who cancelled
- **Reactivation**: former customers who came back

Two businesses can both grow MRR by €5,000 in a month. One does it with healthy new business and expansion. The other is bleeding churn and papering over it with an expensive acquisition push. Movements are how you tell those two apart.

### NRR, GRR and Quick Ratio

Three ratios summarize the health of your existing base over a period:

- **Gross Revenue Retention (GRR)**: how much recurring revenue you keep from existing customers, ignoring any expansion. It can never exceed 100%. It's your pure leakage number.
- **Net Revenue Retention (NRR)**: the same, but crediting expansion. Above 100% means your existing customers grow in value faster than they churn, the holy grail of SaaS.
- **Quick Ratio**: new plus expansion MRR divided by contraction plus churn MRR. How much you're growing for every unit you're losing.

### Revenue Churn

Churn deserves its own view over time, and it comes in two flavors:

- **Gross revenue churn**: the share of recurring revenue lost to cancellations and downgrades.
- **Net revenue churn**: the same, offset by expansion and reactivation. This one can go **negative**, which is a good thing: it means expansion outran churn.

### LTV

**Customer Lifetime Value** estimates the total recurring revenue a subscriber generates before they churn, derived from your average revenue per subscriber and your churn rate. It's the number that tells you how much you can afford to spend acquiring a customer, which is exactly where this connects back to marketing (more on that below).

---

## The Part No Subscription Tool Can Do

Every metric above exists in ChartMogul or Baremetrics too. Here's the one thing they structurally cannot offer: **every one of these numbers is attributable to an acquisition channel.**

Because SimpleStats tracks the visitor, the registration, and the payment as one connected chain, you can filter your entire subscription dashboard by source, campaign, or country and watch the metrics re-segment:

- MRR from your Google Ads campaign, specifically
- Net revenue retention of customers who came from Germany
- LTV of subscribers acquired through your newsletter

Stripe doesn't know your `utm_campaign`. ProfitWell doesn't either. SimpleStats sits at the intersection of subscription analytics (like Paddle) and channel attribution (like Plausible), which in the Laravel world is a combination nobody else offers.

And that's the bridge to the profit question: LTV per channel plus acquisition cost per channel gives you **LTV:CAC per channel**, the number that tells you which campaigns to scale. We cover that in detail in [Marketing ROI for Laravel](/blog/marketing-roi-for-laravel).

---

## Telling SimpleStats About Your Subscriptions

To power MRR and the rest, SimpleStats needs to know that a payment is recurring, and on what interval. You provide that through the same `TrackablePayment` contract you already use for payment tracking, via the `getTrackingSubscription()` method:

```php
<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Laravel\Paddle\Transaction as PaddleTransaction;
use SimpleStatsIo\LaravelClient\Contracts\TrackablePayment;
use SimpleStatsIo\LaravelClient\Contracts\TrackablePerson;
use SimpleStatsIo\LaravelClient\Data\TrackingSubscription;
use SimpleStatsIo\LaravelClient\Enums\SubscriptionInterval;

class Transaction extends PaddleTransaction implements TrackablePayment
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
        // Return null for one-time payments.
        if (! $this->isSubscription()) {
            return null;
        }

        return new TrackingSubscription(
            plan: 'pro',
            interval: SubscriptionInterval::Month,
        );
    }
}
```

Two things to note:

- **Return `null` for one-time payments.** A charge that isn't part of a subscription (a lifetime deal, a one-off purchase) simply returns `null` here and stays out of your MRR, exactly where it belongs.
- **The `plan` is a free-form string.** Use whatever names your billing uses (`pro`, `team`, `enterprise`). It lets you segment subscription metrics by plan later.

The `interval` (`Month` or `Year`) is what SimpleStats uses to normalize every subscription into a monthly figure for MRR. A €120/year plan and a €10/month plan both contribute €10 to MRR, as they should.

Using Cashier Stripe, Cashier Paddle, Cashier Mollie or Polar? Then the interval and plan are tracked for you with [one line of config](/docs/payment-providers.html). For your own payment model, see the [Cashier payment tracking guide](/blog/how-to-track-laravel-cashier-payments).

---

## One Dashboard Instead of Three

You don't need a separate subscription-analytics subscription to see your MRR. And you definitely don't need to accept that your subscription metrics and your marketing metrics live in different universes.

Track your payments once, mark the recurring ones, and MRR, movements, retention, churn, and LTV all show up next to the campaigns and channels that produced them, server-side, GDPR-compliant, in your Laravel app.

---

*This is part two of a three-part series on going from traffic metrics to business metrics in Laravel. Start with [Marketing ROI for Laravel](/blog/marketing-roi-for-laravel), and read on about [Laravel retention analytics](/blog/laravel-retention-analytics).*

*[SimpleStats](/) is a server-side analytics platform built specifically for Laravel. It tracks visitors, registrations, logins, and payments, all GDPR-compliant, all unaffected by ad blockers, all connected through UTM attribution. Set it up in minutes with a single Composer package.*
