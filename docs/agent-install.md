---
description: "Instructions for AI coding agents to install the SimpleStats Laravel client in a Laravel app: package, API token, frontend setup, registration and payment tracking."
---

# Agent Install

These are the official instructions from _SimpleStats_ for AI coding agents (Claude Code, Cursor, Codex, ...) to install the SimpleStats client in the user's Laravel app. For humans, the same setup is documented in [Installation](installation.md).

Work in the user's repository: run the commands and edit the files yourself. Before you change any file, tell the user in a few lines what you are going to change and wait for their OK. Never commit or push unless the user asks for it. Follow the repository's own agent instructions (`AGENTS.md`, `CLAUDE.md`) for code style, but do not install extra tools they mention unless the user asks for it.

## 1. Gather the Details

The user's prompt contains:

- **API token:** the token of the SimpleStats project. It is a secret: write it only into `.env`, never into a committed file, a config file or your final summary.
- **API URL:** only present when SimpleStats does not run on simplestats.io (self-hosted). Copy it as given, with or without a trailing slash. Without it, keep the package default and do not add the key.
- **Tracking:** which data the project tracks (`visitors`, `registrations`, `payments`, `subscriptions`). Only set up what is listed.
- **Domain:** the domain the user entered for the project, if any. It is usually the production domain of the app, so it rarely matches the local `APP_URL`.

If the prompt has no API token, ask the user for it. They find it in SimpleStats under **Projects** > their project > **Manage API Token**.

## 2. Check the App

- Read `composer.json`. The client supports Laravel **11, 12 and 13**. For Laravel 8 to 10, require `simplestats-io/laravel-client:^3.0` instead and tell the user that newer features (beacon, payment providers) are not available there.
- Not a Laravel app at all? Stop and point the user to the [plain PHP client](https://github.com/simplestats-io/php-client).
- If `simplestats-io/laravel-client` is already installed, check its config instead of installing it again.

## 3. Install the Package

```bash
composer require simplestats-io/laravel-client
php artisan vendor:publish --tag="simplestats-client-config"
```

Add to `.env`:

```dotenv
SIMPLESTATS_API_TOKEN=<token from the prompt>
```

With an API URL in the prompt, also add `SIMPLESTATS_API_URL=<API URL from the prompt>`.

If the repository has an `.env.example`, add `SIMPLESTATS_API_TOKEN=` there with an empty value. Copy `SIMPLESTATS_API_URL` with its real value (it is not secret), never empty: an empty value overrides the package default and breaks the connection.

If the app caches its config (`bootstrap/cache/config.php` exists), run `php artisan config:clear`.

## 4. Pick the Frontend Setup

Find out how visitors reach the app, because the client only sees requests that reach Laravel.

- **Laravel renders the pages** (Blade views, Livewire, Inertia with `inertiajs/inertia-laravel`): nothing to do, the default config covers it.
- **A separate frontend app calls the Laravel API** (no page views in Laravel, a Next.js / Nuxt / SPA project next to an API-only Laravel app, `next.config.*` or `nuxt.config.*` in the repository): set `'middleware_groups' => ['api']` and `'tracking_storage' => 'cache'` in `config/simplestats-client.php`, then add the beacon script as for a marketing site (next bullet), with the frontend's domain in `own_domains`. Requires client version 6.4 or later. If signup or checkout runs through the frontend's server (Next.js server actions, Nuxt server routes) instead of the browser, tell the user and follow [Headless, SPA & Stateless](headless-stateless-spa.md#forwarding-manually) instead.
- **A marketing site outside Laravel** (landing page on Webflow, Framer, WordPress, Next.js, Nuxt or Astro, the app on Laravel): follow [Works with Any Frontend](frontend-frameworks.md#marketing-site-outside-laravel). In short: `SIMPLESTATS_BEACON_ENABLED=true` in `.env`, the marketing domain in `own_domains`, and `<script src="https://<laravel-app-domain>/assets/v.js" defer></script>` on every page of the marketing site. If the marketing site runs on another domain than the app, also set `'tracking_storage' => 'cache'`.

The repository rarely shows whether a marketing site exists elsewhere. Unless the repository clearly shows a separate frontend, ask the user once: "Is your landing page (`<domain>`) rendered by this Laravel app, or does it run on another platform?" Do not decide it by comparing the domain with `APP_URL`. Only a marketing site outside Laravel needs `own_domains`, a domain the Laravel app serves itself needs no config. If the marketing site lives in this repository, add the script there yourself, using the guide for its framework ([Next.js](nextjs.md), [Nuxt](nuxt.md), [Astro](astro.md)). For hosted builders ([Webflow](webflow.md), [Framer](framer.md), [WordPress](wordpress.md)) tell the user where to paste it.

## 5. Track Registrations

Only if the tracking includes `registrations`.

Implement `SimpleStatsIo\LaravelClient\Contracts\TrackablePerson` on the user model (the one in `tracking_types.user.model` of the config, `App\Models\User` by default):

```php
use Carbon\CarbonInterface;
use SimpleStatsIo\LaravelClient\Contracts\TrackablePerson;

class User extends Authenticatable implements TrackablePerson
{
    public function getTrackingTime(): CarbonInterface
    {
        return $this->created_at;
    }
}
```

Only if the model implements `MustVerifyEmail`, ask the user whether a registration should only count once the email is verified. If yes, use `TrackablePersonWithCondition` as described in [How to track a new user](how-to-track-a-new-user.md#with-condition).

## 6. Track Payments

Only if the tracking includes `payments`. Registrations must be set up as well, payments are linked to the paying user.

Check `composer.json` for a supported payment package and set its provider in `config/simplestats-client.php`:

| Package | Provider |
|---------|----------|
| `laravel/cashier` | `SimpleStatsIo\LaravelClient\PaymentProviders\CashierStripe` |
| `laravel/cashier-paddle` | `SimpleStatsIo\LaravelClient\PaymentProviders\CashierPaddle` |
| `mollie/laravel-cashier-mollie` | `SimpleStatsIo\LaravelClient\PaymentProviders\CashierMollie` |
| `danestves/laravel-polar` | `SimpleStatsIo\LaravelClient\PaymentProviders\Polar` |
| `lemonsqueezy/laravel` | `SimpleStatsIo\LaravelClient\PaymentProviders\LemonSqueezy` (being phased out, see [Payment Providers](payment-providers.md#lemon-squeezy)) |

```php
use SimpleStatsIo\LaravelClient\PaymentProviders\CashierStripe;

'tracking_types' => [
    // ...
    'payment' => [
        'provider' => CashierStripe::class,
        'model' => null,
    ],
],
```

Change the existing `payment` entry under `tracking_types`, do not add a second one.

Then read the section for that provider in [Payment Providers](payment-providers.md) and tell the user which webhook events they must enable in the provider's dashboard. You cannot do that step for them, and payments are silently missing without it. Subscriptions need nothing extra with a provider: monthly and yearly plans are tracked with their plan and interval.

If the package is required but not set up yet (e.g. no `Billable` trait on the user model, no Cashier migrations), still set the provider, but do not set up the payment package yourself. Tell the user that payments are only tracked once it works.

No supported package, or the billable model is not the user (e.g. a team)? Find the model that stores completed payments and implement `TrackablePayment` on it as described in [How to track a new payment](how-to-track-a-new-payment.md). Amounts are in **cents**, the currency is an ISO-4217 code. If several models could be the payment, ask the user which one. If the tracking includes `subscriptions`, return the plan and interval from `getTrackingSubscription()` for recurring payments.

## 7. Check the Queue

The client sends its API calls through the queue. Read `QUEUE_CONNECTION`:

- `sync`: works, but the API call runs inside the request.
- Any other driver: a queue worker must run, locally (`php artisan queue:work`) and in production. Nothing reaches SimpleStats before it runs. Page views (on by default) are collected best by a long-running worker, and some payment providers need it as well. See [Queue](queue.md).

Do not change the queue setup, only tell the user what applies.

## 8. Verify

```bash
php artisan simplestats:status
```

It checks the token, the API connection, the queue and the payment provider. Fix what it reports. A note that no long-running queue worker was seen only means none has run yet: tell the user to start one. The command does not check the user model, so confirm that it implements the contract yourself.

Then ask the user to start the app and, if the queue is not `sync`, a queue worker, and to open their site in a browser (a new private window works best): the SimpleStats setup page updates as soon as the first visitor arrives. If nothing arrives, go through [Troubleshooting](troubleshooting.md) with the user.

## 9. Finish

Tell the user:

- which files you changed, in a short list (without the token),
- what is left for them: webhook events, a running queue worker in production, the script on a hosted marketing site, deploying the change,
- that they can also connect SimpleStats to their agent to ask questions about their data later, see [Agent Setup](agent-setup.md).
