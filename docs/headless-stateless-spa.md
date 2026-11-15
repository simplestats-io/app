# Headless, SPA & Stateless Backends

The _SimpleStats_ Laravel client supports two storage drivers for visitor attribution: the classic Laravel **session** (default) and the **cache**. The cache driver is what makes the package work in headless setups where the frontend lives on a separate domain (Vue, React, Next, mobile apps), and in stateless auth setups (JWT, Bearer tokens, Sanctum personal access tokens) where no session cookie is available.

## Setup profiles

Pick the profile that matches your app.

### Server-rendered (default)

Classic Laravel apps with Blade, Inertia, or Livewire. The browser hits your backend directly. **No setup needed**, the defaults cover this case.

```php
// config/simplestats-client.php
'middleware_groups' => ['web'],
'tracking_storage' => 'session',
```

### Headless / SPA

Your frontend lives on a separate domain (Vue, React, Next, mobile app) and only ever talks to your Laravel API.

```php
// config/simplestats-client.php
'middleware_groups' => ['api'],
'tracking_storage' => 'cache',
```

### Hybrid

Marketing pages in Blade, dashboard as a SPA, both backed by the same Laravel app.

```php
// config/simplestats-client.php
'middleware_groups' => ['web', 'api'],
'tracking_storage' => 'cache',
```

## How attribution works in a headless setup

In a server-rendered app, the browser hits your backend directly, so the middleware sees the visitor's URL, the `Referer` header, and any UTM parameters in the query string out of the box.

In a headless / SPA setup, the browser hits your **frontend** first (separate domain or static host). Your Laravel API only ever sees the API request, which means:

- The `Referer` header points at your own frontend domain, not the original referrer like `google.com`.
- The request path is something like `/api/me`, not the landing page the visitor actually saw.
- UTM parameters from the landing URL are lost unless the frontend forwards them explicitly.

To make attribution work, your frontend must send **one initial tracking request** to any GET endpoint behind the `CheckTracking` middleware as soon as the page loads, and forward the original context as headers or query parameters. The middleware caches that context for the rest of the day, so later events (registrations, logins, payments) are automatically attributed to the same visit. **One call is enough**, you do not need to repeat the headers on every subsequent request.

## What to forward

The middleware accepts the following values from the request itself (in addition to the standard sources like `$_SERVER['HTTP_REFERER']` and the request path):

| Field        | Query / Body                              | Header                       | Notes                                                |
|--------------|-------------------------------------------|------------------------------|------------------------------------------------------|
| UTM source   | `utm_source`, `ref`, `referer`, `referrer`| `X-Utm-Source` (or aliases)  | Aliases configurable in `simplestats-client.tracking_codes` |
| UTM medium   | `utm_medium`, `adGroup`, `adGroupId`      | `X-Utm-Medium` (or aliases)  |                                                      |
| UTM campaign | `utm_campaign`                            | `X-Utm-Campaign`             |                                                      |
| UTM term     | `utm_term`                                | `X-Utm-Term`                 |                                                      |
| UTM content  | `utm_content`                             | `X-Utm-Content`              |                                                      |
| Referer      | `document_referer`                        | `X-Document-Referer`         | The original `document.referrer`, not the API caller |
| Page         | `page`                                    | `X-Page`                     | The path the visitor actually landed on. The query value must start with `/`, anything else (e.g. a pagination `?page=2`) is ignored |

## Frontend example

Place this at the top of your SPA's root component, so it runs on the very first page load:

```js
const params = new URLSearchParams(location.search)

const referrer = document.referrer;

// this check drops your own frontend as a referrer on in-app navigation, reloads, or expired sessions, only an external referrer is forwarded.
const documentReferer = referrer && new URL(referrer).hostname !== location.hostname ? referrer : '' ;

fetch('https://api.your-app.com/track-init', {
  method: 'GET',
  credentials: 'include',
  headers: {
    'X-Utm-Source': params.get('utm_source') ?? '',
    'X-Utm-Medium': params.get('utm_medium') ?? '',
    'X-Utm-Campaign': params.get('utm_campaign') ?? '',
    'X-Utm-Term': params.get('utm_term') ?? '',
    'X-Utm-Content': params.get('utm_content') ?? '',
    'X-Document-Referer': documentReferer,
    'X-Page': location.pathname,
  },
})
```

That single fire-and-forget call populates the cache for this visitor for the rest of the day. Every later event (login, registration, payment) is automatically linked back to it.

## Backend endpoint

The middleware is appended to the groups listed in `middleware_groups` automatically. You can then point the frontend init call at any GET route in those groups, including one you already have like `/api/me` or a dedicated `/api/track-init`:

```php
// routes/api.php
Route::get('/track-init', fn () => response()->noContent());
```

Make sure the route does **not** match the `except` list in `config/simplestats-client.php`.

::: warning Always use middleware groups, not per-route attachment
The middleware does more than tracking the initial visit: it also sets the visitor context that later events (registration, login, payment) rely on for attribution. If you attach the middleware only to a single init route, every other route loses the context and attribution data gets dropped silently. Always register via `middleware_groups`, so every relevant request sets the context.
:::

## Cache requirements

Attribution lives in Laravel's `Cache` for the day. Behind multiple replicas, the cache must be shared across instances, e.g. via `redis` (recommended) or `memcached`. The `file` driver only works on a single host. The `array` driver is in-memory per request and cannot persist anything, so it is not usable for tracking storage.

Keep in mind that clearing the cache (for example via `php artisan cache:clear` on deploy) drops in-flight visitor attribution for the day. Consider clearing more selectively if you need to wipe cache regularly.

## Precision on mobile networks

The cache driver identifies the visitor by [the visitor hash](/how-to-track-a-new-visitor#the-visitor-hash), which is derived from `IP + User-Agent + day`. When a mobile user switches between WiFi and 4G, the IP changes, so the visitor hash changes, and the same person can be counted as two visitors for the day.

In practice, this is mostly a visitor-count issue. Logins and payments stay accurate because they are linked to a user ID, and the user's UTM attribution is captured once at registration and re-used from there.

The one case where it matters is a network switch **between** the initial landing page and the registration itself: if the IP changes in that window, the registration is recorded without UTMs and any later payments inherit that. In typical funnels the switch is rare (users tend to finish signing up on the network they started on), but it is a real edge of the precision/consent trade-off and worth knowing about.

This is the same trade-off that privacy-first tools like Plausible and Fathom make: they accept a small amount of over-counting in exchange for staying cookie- and consent-free. Persisting a visitor identifier on the user's device (in `localStorage` or a cookie) would fall under the ePrivacy directive in the EU and require an explicit consent banner, which defeats the purpose of server-side tracking.
