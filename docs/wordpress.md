---
description: "Track visitors of a WordPress site with SimpleStats and attribute registrations and payments in your Laravel app to their real source."
---

# SimpleStats with WordPress

A WordPress site in front of a Laravel app (signup, login and payments in Laravel) uses the [beacon script](./static-caching.md#marketing-site-outside-laravel). A native WordPress plugin is not available yet, so the script is added by hand.

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

The script has to end up on every page. Pick one of these options:

**A header and footer code plugin.** Paste the script into the site-wide header or footer field of a plugin like WPCode:

```html
<script src="https://app.my-app.com/assets/v.js" defer></script>
```

**Your (child) theme.** Enqueue the script in `functions.php`:

```php
add_action('wp_enqueue_scripts', function () {
    wp_enqueue_script('simplestats', 'https://app.my-app.com/assets/v.js', [], null, [
        'strategy' => 'defer',
        'in_footer' => true,
    ]);
});
```

Or paste the `<script>` tag into the theme's `footer.php`, right before `wp_footer()`. Use a child theme, otherwise a theme update overwrites the change.

## Good to know

- Replace `app.my-app.com` with the domain of your Laravel app. If you changed the beacon path (`SIMPLESTATS_BEACON_PATH`), adjust the script path as well.
- Marketing site and app on sibling subdomains (`www.my-app.com` and `app.my-app.com`) work with the default `session` storage. On **different registrable domains** (`my-app.com` and `my-app.io`, or a preview domain), set `tracking_storage` to `cache`.
- Keep the default `Referrer-Policy` on the marketing site, or at least `origin`. With `no-referrer` no visitors are counted.

All details and caveats: [Marketing site outside Laravel](./static-caching.md#marketing-site-outside-laravel) and [Headless, SPA & Stateless](./headless-stateless-spa.md).
