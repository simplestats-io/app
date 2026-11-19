---
description: "Keep counting visitors with SimpleStats when pages are served from Statamic static caching, nginx, Varnish or Cloudflare caches."
---

# Static Caching & CDNs

Server-side tracking needs your application to actually run. If your pages are served from a cache that bypasses PHP entirely, the `CheckTracking` middleware never runs and those visits are never counted.

This affects setups like:

- **Statamic** with the `full` measure static caching strategy (nginx/Apache serves pre-rendered HTML files from `public/static`)
- **nginx or Varnish** page caching in front of your app
- **Cloudflare** page rules or "Cache Everything"
- **`spatie/laravel-responsecache`** in a configuration that returns before the middleware stack

A quick way to confirm this is the cause: open a cached page, then check whether your app logs any request at all. If nothing reaches PHP, nothing can be tracked.

## The lossless option first

Before adding anything, check whether you actually need a cache that skips PHP.

Statamic's **half measure** static caching keeps Laravel in the request. The rendered response comes from the application cache, so you still save the expensive Statamic render, but the middleware stack runs exactly as before. Tracking stays fully server-side and ad-blocker proof, with no snippet and no configuration.

```php
// config/statamic/static_caching.php

'strategy' => 'half',
```

For most content sites this is the better trade-off. Only reach for the beacon below if you truly need PHP out of the request path.

::: warning The beacon moves part of the tracking to the client
The beacon is requested by the browser, which means it can be blocked. This gives up part of what makes server-side tracking accurate in the first place. Use it when full static caching is a hard requirement, not by default.
:::

## How the beacon works

The client package can register a tiny GET endpoint that returns a 1x1 pixel. Cached pages request it once per page view, which gives the middleware a real request to work with: correct IP, correct User-Agent, correct timing, and the same bot filtering and visitor deduplication as any other request.

The endpoint reads the visitor's context from two sources:

- **Without JavaScript.** The browser sends the embedding page as the `Referer` of the pixel request, and for same-origin requests that header carries the **full URL including the query string**. The package derives the entry page and all `utm_*` parameters from it.
- **With JavaScript.** Only the browser knows `document.referrer`, the site the visitor actually came from. The snippet forwards it explicitly, along with the page and the query string.

So the JavaScript snippet is the recommended setup, and the `<noscript>` pixel keeps visitors, entry pages and campaign attribution intact when JavaScript does not run. The only thing lost in that case is the external referrer.

## Setup

### 1. Enable the beacon

```php
// config/simplestats-client.php

'beacon' => [
    'enabled' => env('SIMPLESTATS_BEACON_ENABLED', false),
    'path' => env('SIMPLESTATS_BEACON_PATH', 'assets/v'),
],
```

```env
SIMPLESTATS_BEACON_ENABLED=true
```

The route is registered in the first group listed in `middleware_groups`, so it always carries the tracking middleware. Make sure the path does not match your `except` list, the package logs a warning if it does.

::: tip Choosing a path
Paths containing `track`, `stats`, `analytics`, `beacon`, `collect` or `pixel` are blocked by common ad blocker lists. A path with a file extension like `.gif` is worse still: many CDNs cache static extensions by default, so the request would be answered at the edge and never reach your app. An extensionless, asset-looking path such as `assets/v` avoids both problems.

If you change the path in production, run `php artisan route:clear` (or re-run `route:cache`).
:::

### 2. Add the snippet to your layout

```html
<script>
    (function () {
        var params = new URLSearchParams(window.location.search);
        params.set('page', window.location.pathname);
        params.set('document_referer', document.referrer);
        fetch('/assets/v?' + params.toString(), { keepalive: true });
    })();
</script>
<noscript>
    <img src="/assets/v" alt="" width="1" height="1" style="position:absolute">
</noscript>
```

Adjust both URLs if you configured a different path.

Forwarding `window.location.search` keeps campaign attribution working, and `document.referrer` is what makes traffic show up under its real source instead of as direct.

## Caveats

**`navigator.sendBeacon` does not work.** It sends a POST, and only GET requests are tracked. Use `fetch` as shown above.

**A strict `Referrer-Policy` disables the no-JavaScript fallback.** The pixel relies on the browser sending the full same-origin URL, which is the default behaviour (`strict-origin-when-cross-origin`). If your site sets `no-referrer` or `origin`, the pixel can still count the visitor, but the entry page and the campaign parameters are lost. In that case only the JavaScript snippet carries the context.

**Exclude the beacon path from every cache in front of your app.** The response already sends `Cache-Control: no-store`, but CDNs and reverse proxies configured to ignore origin headers must be told explicitly.

**Requests from a foreign domain are not counted.** If the pixel ends up in a copy of your HTML on someone else's site (a mirror, a scraper, an email preview proxy), the referer gives it away and the request is dropped instead of inflating your visitor count.

**A catch-all route can shadow the beacon.** Package routes are registered after your own `routes/web.php`, so a wildcard route like `Route::get('/{any}')->where('any', '.*')` answers the beacon path first. Check with `php artisan route:list --path=assets/v` if the endpoint does not respond as expected.

**The beacon path never shows up as an entry page.** When no page can be determined, the visit is still counted, but the entry page stays empty.

## Statamic

Add the snippet to your layout, for example `resources/views/layout.antlers.html`, right before the closing `</body>` tag.

A few Statamic specifics worth knowing:

- **You do not need to add the beacon path to `exclude.urls`.** Statamic's static caching middleware only runs in its own `statamic.web` middleware group, while the beacon route lives in `web`. It is never statically cached in the first place.
- **The nginx rewrite rules take care of themselves.** They fall back to `index.php` whenever no static file matches, which is always the case for the beacon path.
- **`{{ nocache }}` is not an alternative.** Statamic's nocache endpoint is a POST route, which is not tracked.

## Headless and SPA setups

If your frontend is a separate application rather than a cached version of your Laravel app, the beacon is not what you want. See [Headless, SPA & Stateless](./headless-stateless-spa.md) instead, it covers the same context forwarding with headers and the `cache` tracking storage.
