---
description: "Fix common SimpleStats setup issues: wrong API token, cached config, OPCache, queue workers and missing contracts."
---

# Troubleshooting

If _SimpleStats_ isn't working for your app despite a correct setup, here's how to resolve common issues:

## Double-Check the API Token

Verify that the [API token](installation.html#configuration) in your `.env` file matches the one from our dashboard. You can review it under **Projects** > **Your projects** > **Manage API Token**. Ensure there are no extra spaces or typos.

## Restart Your Config

After updating the **API token** in your `.env` file, refresh your configuration by running:  
`php artisan config:cache`

## Restart PHP-FPM (if using OPCache)

If you're using OPCache, changes to PHP files may require restarting PHP-FPM:  
`sudo service php-fpm reload`

## Re-trigger a Visit

_SimpleStats_ tracks you as a new [Unique Visitor](how-to-track-a-new-visitor.html) only once per day. How to force a fresh visit depends on your configured `tracking_storage` (see [installation](installation.html#configuration)):

- **`session`** (default): clear your session cookies via **Browser Console** > **Application** > **Cookies**, then revisit your site.
- **`cache`** (headless / SPA setups, see [Headless, SPA & Stateless](headless-stateless-spa.html)): the visit is identified by a daily hash of IP + User-Agent, so a new tab or incognito window won't trigger a new visit. Either drop the entry for your visitor hash from the cache, run `php artisan cache:clear`, or wait for the next day.

## Check Whether Requests Reach PHP at All

If no visits are recorded even though you have traffic, your pages may be served from a cache that bypasses your application (Statamic `full` measure static caching, nginx/Varnish page caching, Cloudflare "Cache Everything"). The tracking middleware never runs in that case. See [Static Caching & CDNs](static-caching.html).

## Fix Your Queue

Check your `QUEUE_CONNECTION` setting in the `.env` file:
- If it's **not** set to `sync`, switch it to `sync` and test again. If it works, your queue setup needs attention.
- For `database` queues, ensure the queue is running. Locally, run `php artisan queue:listen` to test.
- For `redis` queues, confirm proper setup: [Laravel Queue Docs](https://laravel.com/docs/12.x/queues).

Ensure your production server's queue is also operational!

## Add the Trackable Contracts

If new [registrations](how-to-track-a-new-user.html) or [payments](how-to-track-a-new-payment.html) aren't appearing on the dashboard:
- Confirm you've correctly implemented the tracking contracts.
- Verify your user/payment models are listed in the `tracking_types` section of your [config file](installation.html#configuration).

## Deferred Function Compatibility

If you're on Laravel 11.x and above and your application's skeleton still contains an `app/Http/Kernel.php` file,
you should add the `InvokeDeferredCallbacks` middleware to the beginning of the kernel's `$middleware` property:

```php
//app/Http/Kernel.php

    // ...
    
    protected $middleware = [
        \Illuminate\Foundation\Http\Middleware\InvokeDeferredCallbacks::class, 
        \App\Http\Middleware\TrustProxies::class,
        // ...
    ];
    
    // ...
```
