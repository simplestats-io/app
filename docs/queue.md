---
description: "Run SimpleStats tracking through Laravel's queue so API calls never block your requests, with retries and fault tolerance."
---

# Queue

To **prevent** the tracking API calls to the _SimpleStats_ server **blocking** your app requests and to ensure fault tolerance and retries, we **strongly** recommend using Laravel's built-in [queue service](https://laravel.com/docs/12.x/queues).

::: info
Starting from version [2.0.13](https://github.com/simplestats-io/laravel-client/releases/tag/v2.0.13), the _SimpleStats_ client attempts to use Laravel's [defer](https://laravel.com/docs/12.x/helpers#deferred-functions) helper (supported since Laravel 11) to handle API calls to our server. This reduces the necessity of using a queue, as API calls no longer block your requests. However, for fault tolerance, retries, or high traffic, we still strongly recommend using the queue.
:::

::: warning
Should you still prefer to not use the queue, you can skip this section and head over to the next page of our documentation. But be aware if something went wrong during API communication, you may **lose** important tracking **data** information!
:::

## Configuration

Here you can define to which queue the tracking **API calls** should be **dispatched**:

```php
//config/simplestats-client.php

    'queue' => env('SIMPLESTATS_QUEUE', 'default'),

```

Since v6 every [page view](how-to-track-page-views.md) is a small job as well. A running queue worker collects them and sends them in one request per minute.

## Job Monitoring

It's important to know if your queue is **running** and if the API calls are getting dispatched **successfully**. For that, if you want something light-weight, we highly recommend spaties [laravel-failed-job-monitor](https://github.com/spatie/laravel-failed-job-monitor) package, which can notify you via mail or Slack when a queued job fails. If you want something more **robust**, you may use [Horizon](https://laravel.com/docs/12.x/horizon), Laravel's incredible powerful native queue monitoring service!

## Fault Tolerance and Retries

Despite our best efforts to maintain continuous availability, the complex nature of API communications means that **interruptions can occur** for various reasons, ranging from network issues on your end to temporary outages of our service. To mitigate these challenges, we've designed the _**Job**_ that communicates with our API to be **resilient**, automatically retrying failed API calls multiple times with specified intervals between attempts. This strategy helps **safeguard** against data loss during unexpected disruptions in communication.

In the event you suspect data was not captured due to prolonged service interruptions, our [Data Import](./how-to-import-existing-data.md) feature provides a straightforward way to synchronize the missing information with our system, ensuring your analytics remain complete and accurate.

