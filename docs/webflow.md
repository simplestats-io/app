---
description: "Track visitors of a Webflow site with SimpleStats and attribute registrations and payments in your Laravel app to their real source."
---

# SimpleStats with Webflow

A Webflow marketing site in front of a Laravel app (signup, login and payments in Laravel) uses the [beacon script](./static-caching.md#marketing-site-outside-laravel).

## 1. Prepare your Laravel app

Enable the beacon in the `.env` of your Laravel app:

```env
SIMPLESTATS_BEACON_ENABLED=true
```

List the domain of the marketing site in `own_domains`, otherwise the beacon ignores requests from it and the app records it as a referer:

```php
// config/simplestats-client.php

'own_domains' => [
    'www.my-app.com',
],
```

## 2. Add the script

In Webflow, open **Site settings > Custom code** and paste the script into **Head code** (or **Footer code**):

```html
<script src="https://app.my-app.com/assets/v.js" defer></script>
```

Save and publish the site. Site-wide custom code is part of every page from then on.

## Good to know

- Replace `app.my-app.com` with the domain of your Laravel app. If you changed the beacon path (`SIMPLESTATS_BEACON_PATH`), adjust the script path as well.
- Marketing site and app on sibling subdomains (`www.my-app.com` and `app.my-app.com`) work with the default `session` storage. On **different registrable domains** (`my-app.com` and `my-app.io`, or a preview domain), set `tracking_storage` to `cache`.
- Keep the default `Referrer-Policy` on the marketing site, or at least `origin`. With `no-referrer` no visitors are counted.

All details and caveats: [Marketing site outside Laravel](./static-caching.md#marketing-site-outside-laravel) and [Headless, SPA & Stateless](./headless-stateless-spa.md).
