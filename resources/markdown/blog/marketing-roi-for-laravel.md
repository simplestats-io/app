---
title: "Marketing ROI for Laravel: ROAS, CAC and LTV per Campaign"
slug: "marketing-roi-for-laravel"
date: "2026-07-01"
excerpt: "Your analytics tool knows how much traffic each campaign sends. Your billing tool knows how much revenue you made. Neither one tells you which campaigns are actually profitable after ad spend and churn. Here's how to close that gap in Laravel."
tags:
  - Laravel
  - Marketing
  - ROAS
  - Attribution
published: true
author_id: 1
---

Here's a question most Laravel teams can't answer with confidence: **which of your marketing campaigns actually make money?**

Not which one sends the most traffic. Not which one drives the most signups. Which one, after you subtract what you spent on ads and account for the customers who churn, leaves you with more money than you put in.

It sounds like a question your tools should answer instantly. They don't. And the reason is structural.

---

## The Gap Between Your Analytics and Your Billing

Look at what each tool in a typical stack actually knows:

- **Google Analytics** knows your traffic. It sees pageviews, sessions, and (if you fight with it long enough) some client-side conversion events. It does not see the payment that happened three weeks later on your server. And 30-50% of its client-side data is silently eaten by ad blockers anyway.
- **Stripe, Paddle, ProfitWell, ChartMogul** know your revenue. They see every charge, every subscription, every refund. What they don't know is where that customer came from. Stripe has never heard of your `utm_campaign`.
- **Plausible, Fathom** know your traffic, privacy-first, and that's the whole point. They deliberately don't touch revenue.

So you end up with two spreadsheets that never meet. One says "Google Ads sent 8,000 visitors." The other says "we made €40,000 this month." Connecting the two is a manual, error-prone, once-a-quarter guessing game.

**The number that matters lives in the gap between those two tools.**

---

## Revenue Per Channel Is a Trap

Even teams that manage to stitch revenue back to a channel usually stop at the wrong number: **top-line revenue per campaign.** That number lies to you in two directions.

It ignores **cost.** A campaign that generated €10,000 in revenue looks great until you remember it cost €12,000 in ad spend.

And it ignores **churn.** A campaign that acquires cheap subscribers who cancel after one month is worth a fraction of a campaign that acquires customers who stay for two years, even if their first-month revenue looks identical.

Revenue is a vanity number. **Profit after acquisition cost and churn** is the real one.

---

## What SimpleStats Does Differently

SimpleStats already tracks visitors, registrations, logins, and payments **server-side**, and it ties every payment back to the UTM campaign that brought the user in. That attribution is the hard part, and it's already done. No pixel, no cookies, no ad-blocker holes, because it all happens inside your Laravel app.

Once the revenue side is attributed, closing the gap is simple: **you enter your ad spend per campaign.** From there SimpleStats computes the numbers that actually answer the profit question:

- **ROAS (Return on Ad Spend)**: revenue divided by spend. Above 1 means the campaign brought in more than it cost. Below 1 means you're paying to lose money.
- **CAC (Customer Acquisition Cost)**: spend divided by new registrations. What each customer costs you to acquire.
- **Net profit**: attributed revenue minus ad spend, per channel, at a glance.
- **LTV:CAC**: the lifetime value of a customer divided by what it cost to acquire them. The single most important number in subscription businesses. The SaaS rule of thumb is **3 or higher**: you want to earn at least three times what you spend to win a customer.
- **CAC payback**: how many months of recurring revenue it takes to earn back the acquisition cost. Shorter is better. Under 12 months is healthy.

## A Worked Example

Say you're running three campaigns for your Laravel SaaS this month:

| Campaign | Spend | Revenue | ROAS | New customers | CAC | Net profit |
|---|---|---|---|---|---|---|
| Google Ads | €6,000 | €7,200 | 1.2x | 60 | €100 | +€1,200 |
| Twitter Ads | €5,000 | €3,000 | 0.6x | 20 | €250 | −€2,000 |
| Newsletter | €800 | €9,600 | 12.0x | 80 | €10 | +€8,800 |

A traffic-only tool would tell you Google Ads and Twitter are your big spenders and leave it there. A revenue-only tool would tell you total revenue was €19,800 and stop.

Put spend and revenue side by side, per channel, and the real story jumps out:

- **Twitter is actively losing you money.** Every euro spent returns 60 cents. That's a €2,000 hole this month, and no amount of "but look at the reach" fixes it.
- **Google Ads is barely profitable** at 1.2x ROAS. Worth watching, maybe worth optimizing, not worth scaling as-is.
- **The newsletter is the entire business.** With a fraction of the spend it returns 12x and produces more net profit than the other two combined.

Without profit-per-channel, the intuitive move is to pour more budget into the campaigns with the biggest numbers. That's exactly backwards.

---

## Adding the Churn Dimension

The table above still uses this month's revenue. For a subscription business, that undersells your best channels and flatters your worst ones, because it can't see the future.

That's where **LTV:CAC** comes in. LTV (lifetime value) estimates the total recurring revenue a customer will generate before they churn. Divide it by CAC and you get the true quality of a channel:

- A channel with **LTV:CAC below 1** loses money over the customer's whole life, even if this month looked fine.
- A channel between **1 and 3** is viable but thin.
- A channel **at 3 or above** is a channel you scale.

Because SimpleStats knows both the recurring revenue attributed to each campaign and the churn rate of the customers it brought in, it can put an LTV:CAC number next to every campaign, and color-code it against the benchmark so the profitable channels are obvious at a glance.

---

## How to Set It Up

There's no new integration to learn. If you're already tracking payments with SimpleStats, you're most of the way there:

1. **Track payments** so revenue can be attributed to a channel. If you use Cashier Stripe, Cashier Paddle, Cashier Mollie or Polar, it is [one line of config](/docs/payment-providers.html). For any other setup, our [payment tracking guide](/docs/how-to-track-a-new-payment.html) walks through it in a few minutes.
2. **Create a campaign** in SimpleStats and link it to the UTM values you use in your ad URLs.
3. **Enter your ad spend** for that campaign and date range.

That's it. ROAS, CAC, net profit, LTV:CAC, and payback appear per campaign, computed from the revenue SimpleStats already attributed. Use your ad platform for targeting, and SimpleStats for finding out whether that targeting actually pays off.

---

## From Reporting to Deciding

"How much traffic did we get?" is a reporting question. "Which channels make us money after everything?" is a decision. The first fills a slide. The second tells you where next month's budget should go.

Your analytics tool and your billing tool each hold half the answer. SimpleStats is built to hold both halves at once, server-side, in the one place your Laravel app already lives.

---

*This is part one of a three-part series on going from traffic metrics to business metrics in Laravel. Next: [the SaaS metrics stack](/blog/saas-metrics-for-laravel-mrr-churn-ltv) (MRR, churn, and LTV) and [Laravel retention analytics](/blog/laravel-retention-analytics).*

*[SimpleStats](/) is a server-side analytics platform built specifically for Laravel. It tracks visitors, registrations, logins, and payments, all GDPR-compliant, all unaffected by ad blockers, all connected through UTM attribution. Set it up in minutes with a single Composer package.*
