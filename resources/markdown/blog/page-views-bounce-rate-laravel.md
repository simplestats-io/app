---
title: "Page Views, Bounce Rate and Top Pages for Your Laravel App"
slug: "page-views-bounce-rate-laravel"
date: "2026-11-16"
excerpt: "SimpleStats now counts the pages your visitors open. See page views, views per visitor, bounce rate and your top pages, broken down by channel, campaign and entry page. Server-side, without a JavaScript snippet, and free on every plan."
tags:
  - Laravel
  - Analytics
  - Page Views
  - Product Update
published: true
author_id: 1
---

Until now, SimpleStats told you where your visitors come from and what they bring in: registrations, payments, revenue per campaign. Today it also tells you **how far they get on your site.**

With version 6 of the Laravel client, SimpleStats counts the pages your visitors open. No JavaScript snippet, no code changes, and page views are **free on every plan**: they do not count against your monthly event quota.

---

## What You Get

- **Page Views:** how many pages your visitors opened, per day, week or month.
- **Views per Visitor:** how many pages a visitor opens on average.
- **Bounce Rate:** the share of visitors who opened only one page.
- **Top Pages:** your most viewed pages, in a new tab next to Entry Pages.
- **A Views column in every breakdown:** sources, campaigns, referrers, countries, devices and entry pages.

All of it sits on the same dashboard as your visitors, registrations and revenue, so you see engagement and money side by side.

---

## 1. Which Channel Brings Engaged Visitors?

Visitor counts alone can be misleading. Say your dashboard shows this for last month:

| Source | Visitors | Views | Registrations |
|---|---|---|---|
| Google Ads | 4,000 | 5,200 | 40 |
| Newsletter | 1,200 | 6,000 | 36 |

Google Ads brings more than three times the visitors. But newsletter readers open five pages each, and almost as many of them sign up. That is the channel worth more attention, and maybe the ad campaign needs a better landing page.

Because every view belongs to the visitor's first visit of the day, the Views column always answers the question: *"How much did the people this channel brought in look around?"*

## 2. Which Landing Page Makes People Read On?

**Entry Pages** shows where visitors start. The Views column there now tells you what happens next: all pages viewed by visitors who started on that page.

An entry page with many visitors but barely more views than visitors is a dead end. People land, read and leave. A page with three or four views per visitor sends people deeper into your site, towards your pricing page and your signup.

**Top Pages** answers a different question: how often was each page viewed, no matter where the visitor started? Together they show you both the doors into your site and the rooms people actually spend time in.

## 3. Engagement for Sites Without Signups

Not every project has registrations or payments. For a landing page, a docs site or a blog, page views and bounce rate are often the most useful numbers you have. They now show up automatically for every project that sends them, including projects that only track visitors.

---

## Setup: One Composer Command

Update the client to version 6:

```bash
composer require simplestats-io/laravel-client:^6.0
```

That's it. Page views are sent through your queue, so all you need is a running queue worker (`php artisan queue:work` or Horizon). To check that everything is set up correctly:

```bash
php artisan simplestats:status
```

It shows whether page views are on and whether your worker collects them. As soon as the first views arrive, the new numbers show up on your dashboard.

Don't need page views? Turn them off in your `.env`:

```ini
SIMPLESTATS_PAGE_VIEWS=false
```

---

## What Counts as a Page View

A page view is a successful `GET` request for a page, and that includes Inertia, Livewire and Turbo page changes. A few things never count:

- redirects, errors, JSON and file responses
- bots and browser prefetching
- reloading the same page
- pages behind a login, like your app's dashboard or a Filament panel, because that's product usage, not website traffic

Query strings are ignored, so `/pricing?plan=pro` and `/pricing` are the same page.

## Why the Numbers Can Differ From Other Tools

**Your bounce rate is likely lower than in Google Analytics.** Session-based tools count a bounce per visit of up to 30 minutes. SimpleStats counts per day, like it counts visitors: someone who reads one article in the morning and another one in the evening did not bounce.

**You will likely see more page views than in a JavaScript tool.** Page views are counted on your server, so ad blockers and declined cookie banners can't hide them. If you want to know why that matters, read [Server-Side Analytics for Laravel](/blog/server-side-analytics-laravel).

All the details are in the docs: [Page Views](/docs/how-to-track-page-views.html) and [Counting Page Views](/docs/counting-page-views.html).

---

## Try It

Already using SimpleStats? Update the client and your dashboard fills up on its own.

New here? [Start for free](/register) and see not just where your visitors come from, but how far they get and which of them turn into paying customers.
