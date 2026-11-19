---
description: "Server-side analytics for any PHP 8.2+ app with the SimpleStats PHP client. Works with Symfony, Slim, WordPress or plain PHP."
---

# PHP Client

The SimpleStats PHP client lets you add server-side analytics to any PHP 8.2+ application. No framework required. If you're using Symfony, Slim, WordPress, or plain PHP, this is the package for you.

::: tip
Using Laravel? The [Laravel client package](installation.md) provides automatic tracking via middleware, Eloquent observers, and event listeners. You don't need this package.
:::

## Requirements

- PHP 8.2+
- Guzzle 7.5+

## Installation

```bash
composer require simplestats-io/php-client
```

## Configuration

Create a client instance with your API token:

```php
use SimpleStatsIo\PhpClient\SimplestatsClient;

$client = new SimplestatsClient([
    'api_token' => 'your-api-token',
]);
```

Available config options:

```php
$client = new SimplestatsClient([
    'api_token'   => 'your-api-token',       // required
    'api_url'     => 'https://simplestats.io/api/v1/',  // default
    'timeout'     => 5,                      // seconds, default 5
    'max_retries' => 3,                      // default 3
    'enabled'     => true,                   // set false to disable tracking
]);
```

## Visitor Tracking

### Generating a Visitor Hash

SimpleStats identifies visitors with a GDPR-compliant hash that rotates daily. You generate it from the visitor's IP, user agent, and a secret key:

```php
use SimpleStatsIo\PhpClient\VisitorHashGenerator;

$visitorHash = VisitorHashGenerator::generate(
    ip: $_SERVER['REMOTE_ADDR'],
    userAgent: $_SERVER['HTTP_USER_AGENT'],
    secretKey: 'your-secret-key',
);
```

The same visitor gets the same hash within a day, but a different hash the next day. IPs and user agents are never sent to the API.

### Extracting Tracking Data

The `TrackingData` class collects UTM parameters, referrer, IP, user agent, and entry page from the current request:

```php
use SimpleStatsIo\PhpClient\TrackingData;

$trackingData = TrackingData::fromGlobals(appUrl: 'https://yourapp.com');
```

This automatically extracts:
- UTM parameters (`utm_source`, `utm_medium`, `utm_campaign`, `utm_term`, `utm_content`)
- Alternative source param (`ref`)
- HTTP Referer header (excluding exact-host self-referrals)
- Client IP (with Cloudflare/proxy header support)
- Entry page path

You can also build it manually:

```php
$trackingData = new TrackingData(
    ip: '203.0.113.42',
    userAgent: 'Mozilla/5.0 ...',
    source: 'google',
    medium: 'cpc',
    campaign: 'spring-sale',
);
```

### Sending the Visitor Event

```php
$client->trackVisitor($visitorHash, $trackingData);
```

## User Registration Tracking

Track when a user registers. The `$id` should be your application's user ID:

```php
$client->trackUser(
    id: $user->id,
    registeredAt: $user->createdAt,
    trackingData: $trackingData,
);
```

If you want to record a login event at the same time (e.g. auto-login after registration):

```php
$client->trackUser(
    id: $user->id,
    registeredAt: $user->createdAt,
    trackingData: $trackingData,
    addLogin: true,
);
```

## Login Tracking

```php
$client->trackLogin(
    userId: $user->id,
    userRegisteredAt: $user->createdAt,
    ip: $_SERVER['REMOTE_ADDR'],
    userAgent: $_SERVER['HTTP_USER_AGENT'],
);
```

## Payment Tracking

Amounts must be in **cents** (integer). Currency in **ISO 4217** format:

```php
$client->trackPayment(
    id: $payment->id,
    gross: 2000,           // $20.00
    net: 1800,             // $18.00
    currency: 'USD',
    time: $payment->paidAt,
    userId: $user->id,
    userRegisteredAt: $user->createdAt,
);
```

For guest checkouts without a user account, link the payment to a visitor hash instead:

```php
$client->trackPayment(
    id: $payment->id,
    gross: 2000,
    net: 1800,
    currency: 'USD',
    time: $payment->paidAt,
    visitorHash: $visitorHash,
);
```

## Custom Event Tracking

Track any event that doesn't fit the standard lifecycle:

```php
$client->trackCustomEvent(
    id: 'evt_123',
    name: 'plan_upgraded',
    userId: $user->id,
    userRegisteredAt: $user->createdAt,
);
```

## Non-Blocking Tracking

API calls are synchronous by default. To avoid slowing down your response, send the response to the client first and track afterwards:

```php
// Send the response to the browser
fastcgi_finish_request();

// Now track (runs after the response is delivered)
$client->trackVisitor($visitorHash, $trackingData);
```

If your application uses a queue system, dispatch the tracking call as a background job instead.

## Error Handling

The client retries failed requests automatically (up to 3 times with exponential backoff) for connection errors, rate limits (HTTP 429), and server errors (HTTP 5xx).

If all retries fail, an `ApiRequestFailed` exception is thrown:

```php
use SimpleStatsIo\PhpClient\Exceptions\ApiRequestFailed;

try {
    $client->trackVisitor($visitorHash, $trackingData);
} catch (ApiRequestFailed $e) {
    // $e->getMessage() contains status code and response body
    error_log('SimpleStats tracking failed: ' . $e->getMessage());
}
```

## Self-Hosted

Point the client to your own SimpleStats instance:

```php
$client = new SimplestatsClient([
    'api_token' => 'your-api-token',
    'api_url'   => 'https://stats.yourdomain.com/api/v1/',
]);
```
