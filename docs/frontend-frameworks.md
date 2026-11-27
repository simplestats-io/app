---
description: "Use SimpleStats with any frontend: Next.js, Nuxt, Astro, Webflow, Framer, WordPress, React or Vue SPAs and mobile apps in front of a Laravel backend."
---

# Works with Any Frontend

SimpleStats tracks server-side, inside your Laravel app. As long as signup, login and payments run in Laravel, the frontend can be built with anything.

**Blade, Livewire and Inertia need nothing extra.** The browser talks to your Laravel app directly, so the default setup from the [installation](./installation.md) covers them.

For everything else, pick the setup that matches how your frontend is built.

## Marketing site outside Laravel

Your landing page, blog and pricing page live on Next.js, Nuxt, Astro, Webflow, Framer or WordPress, while signup, login and payments run in your Laravel app.

These pages never reach Laravel on their own, so you add a small script that the client package serves next to its [beacon](./static-caching.md#marketing-site-outside-laravel). It counts the marketing visitors with their real source, and their later registrations and payments in the app are attributed to that visit.

In short:

1. Enable the beacon in your Laravel app (`SIMPLESTATS_BEACON_ENABLED=true`).
2. List the marketing domain in `own_domains`.
3. Add `<script src="https://app.my-app.com/assets/v.js" defer></script>` to every page of the marketing site.

## Separate frontend app talking to a Laravel API

The whole frontend is a separate application (Next.js, Nuxt, a React or Vue SPA, a mobile app) and Laravel only serves the API, including signup, login and payments.

Here the tracking middleware runs on your API routes and stores the attribution in the cache instead of the session:

```php
// config/simplestats-client.php
'middleware_groups' => ['api'],
'tracking_storage' => 'cache',
```

Then add the same script as for a marketing site (`SIMPLESTATS_BEACON_ENABLED=true`, the frontend domain in `own_domains`, the `<script>` tag in your HTML). It forwards the referrer, the UTM parameters and the landing page, and reports client-side route changes as page views. Signup and checkout must call the Laravel API from the browser. See [Headless, SPA & Stateless](./headless-stateless-spa.md) for the full setup and the manual alternative.

## Framework guides

- [Next.js](./nextjs.md)
- [Nuxt](./nuxt.md)
- [Astro](./astro.md)
- [Webflow](./webflow.md)
- [Framer](./framer.md)
- [WordPress](./wordpress.md)

Pages served from a static cache in front of Laravel (Statamic full measure, nginx, Varnish, Cloudflare) are covered in [Static Caching & CDNs](./static-caching.md).
