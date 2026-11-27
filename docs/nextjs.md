---
description: "Track visitors of a Next.js site with SimpleStats and attribute registrations and payments in your Laravel app to their real source."
---

# SimpleStats with Next.js

A Next.js marketing site in front of a Laravel app (signup, login and payments in Laravel) uses the [beacon script](./static-caching.md#marketing-site-outside-laravel). If your whole app frontend is built in Next.js against a Laravel API, see [Next.js as the app frontend](#next-js-as-the-app-frontend) below.

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

Load the script once in the root layout with `next/script`, for example in `app/layout.tsx`:

```tsx
import Script from 'next/script'

export default function RootLayout({ children }) {
  return (
    <html lang="en">
      <body>
        {children}
        <Script src="https://app.my-app.com/assets/v.js" strategy="afterInteractive" />
      </body>
    </html>
  )
}
```

With the Pages Router, put the same `<Script>` into `pages/_app.tsx`.

The script also reports client-side navigations, so you do not need to call anything on route changes.

## Good to know

- Replace `app.my-app.com` with the domain of your Laravel app. If you changed the beacon path (`SIMPLESTATS_BEACON_PATH`), adjust the script path as well.
- Marketing site and app on sibling subdomains (`www.my-app.com` and `app.my-app.com`) work with the default `session` storage. On **different registrable domains** (`my-app.com` and `my-app.io`, or a preview domain), set `tracking_storage` to `cache`.
- Keep the default `Referrer-Policy` on the marketing site, or at least `origin`. With `no-referrer` no visitors are counted.

All details and caveats: [Marketing site outside Laravel](./static-caching.md#marketing-site-outside-laravel) and [Headless, SPA & Stateless](./headless-stateless-spa.md).

## Next.js as the app frontend

If signup, login and payments happen in the Next.js app and Laravel only serves the API, switch the client package to the headless setup and add the same script as above:

```php
// config/simplestats-client.php
'middleware_groups' => ['api'],
'tracking_storage' => 'cache',
```

The script also reports client-side route changes. Signup and checkout must call the Laravel API from the browser, not from the Next.js server, otherwise the registration cannot be linked to the visit. Details and the manual alternative: [Headless, SPA & Stateless](./headless-stateless-spa.md).
