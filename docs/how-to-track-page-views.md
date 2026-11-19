---
description: "Track page views in Laravel with SimpleStats: top pages, views per visitor and bounce rate per channel, without extra event quota."
---

# Page Views

Since version 6 of the [Laravel client](installation.md), _SimpleStats_ counts the pages your visitors open. You see how many pages the visitors of each channel, campaign, country or entry page view, your bounce rate and your top pages. There is nothing to add to your code.

::: tip
Page views do not count against your monthly event quota. See [Plans and Limits](plans-and-limits.md).
:::

## Requirements

Page views are sent through your queue, like every other event of the client:

- **With a running queue worker** (`php artisan queue:work` or Horizon) the client collects the views for up to a minute and sends them in one request. This is the recommended setup.
- **Without a long-running worker** (`queue:listen`, Vapor, or no `pcntl` PHP extension) every view is sent on its own. This works, it just means more requests.
- **On the `sync` queue page views are off**, because every view would slow down your page.

Check your setup at any time:

```bash
php artisan simplestats:status
```

It shows whether page views are on and whether a worker collects them. It exits with a non-zero code when something is broken, so it also works as a health check.

## What counts as a page view

A page view is a successful `GET` request for a page, including Inertia, Livewire and Turbo page changes. These never count:

- redirects, errors, JSON and file responses
- browser prefetching
- bots and the paths in the `except` option of the client config
- reloading the same page
- pages behind a login (`auth` middleware, Filament panels and similar), because that is product usage, not website traffic

Query strings are ignored, so `/pricing?plan=pro` and `/pricing` are the same page. Each visitor counts at most 100 views per day.

## Where you see them

- **Breakdowns:** every card on the dashboard gets a **Views** column. In **Entry Pages** these are all pages viewed by visitors who started on that page.
- **Top Pages:** the second tab of the **Pages** card shows how often each page was viewed.
- **KPIs:** **Page Views**, **Views per Visitor** and **Bounce Rate**.

All of this shows up as soon as a project receives page views. How the numbers are calculated is explained in [Counting Page Views](counting-page-views.md).

## Turning it off

```ini
SIMPLESTATS_PAGE_VIEWS=false
```
