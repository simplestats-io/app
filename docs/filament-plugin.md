---
description: "Add a full SimpleStats analytics dashboard to your Filament admin panel: visitors, revenue and conversions without leaving Filament."
---

# Filament Plugin

The SimpleStats Filament plugin adds a full analytics dashboard directly inside your Filament admin panel. Instead of switching between your app and the SimpleStats dashboard, you get visitor trends, revenue charts, conversion rates, and traffic sources right where you already work.

![screenshot](https://simplestats.io/images/screenshot_filament.jpg)

## Requirements

- PHP 8.2+
- Laravel 12+
- Filament v5+
- A SimpleStats API token

::: tip
The plugin uses the same `SIMPLESTATS_API_TOKEN` as the Laravel client package. If you already have the client installed, you don't need a second token.
:::

## Installation

Install the package via Composer:

```bash
composer require simplestats-io/filament-plugin
```

If you haven't already, add your API token to your `.env` file:

```env
SIMPLESTATS_API_TOKEN=your-api-token-here
```

Then register the plugin in your Filament `PanelProvider`:

```php
use SimpleStatsIo\FilamentPlugin\SimplestatsPlugin;

public function panel(Panel $panel): Panel
{
    return $panel
        // ... your existing config
        ->plugin(SimplestatsPlugin::make());
}
```

Your analytics dashboard is now available at `/admin/simplestats` (or your custom panel path + `/simplestats`).

## Configuration

### Via Plugin Methods

You can configure the plugin directly in your `PanelProvider` using the fluent API:

```php
SimplestatsPlugin::make()
    ->navigationGroup('Analytics')
    ->navigationLabel('Stats')
    ->navigationSort(5)
    ->navigationIcon('heroicon-o-chart-bar-square')
    ->cacheTtl(120)
```

### Via Config File

Optionally, publish the config file for environment-based settings:

```bash
php artisan simplestats-filament:install
```

This creates `config/simplestats-filament.php`:

```php
return [
    'api_url' => env('SIMPLESTATS_API_URL', 'https://simplestats.io/api/v1'),
    'api_token' => env('SIMPLESTATS_API_TOKEN'),
    'cache_ttl' => 60,
];
```

Plugin method calls take priority over config values.

## Widgets

The dashboard includes seven pre-built widgets:

### Stats Overview

Six KPI cards at the top of the dashboard:

- **Visitors** and **Registrations** with trend indicators
- **CR** (Conversion Rate) from visitor to registration
- **Net Revenue** in your team's default currency
- **ARPU** (Average Revenue Per User)
- **ARPV** (Average Revenue Per Visitor)

Each card shows the percentage change compared to the previous period when a comparison is active.

### Charts

- **Visitors & Registrations Chart:** Line chart showing both metrics over time, with optional comparison period overlay
- **Revenue Chart:** Gross and net revenue trends over the selected time range

### Top Lists

- **Top Sources:** Traffic sources ranked by visitor count (utm_source)
- **Top Countries:** Geographic distribution of visitors
- **Top Referrers:** Referring domains that send visitors to your site
- **Entry Pages:** Landing pages where visitors arrive first

## Time Range Filter

The dashboard includes a time range dropdown with the following presets:

- Today / Yesterday
- Last 7 Days / Last 30 Days
- Last 12 Weeks / Last 6 Months
- This Month / Last Month
- This Year / Last Year
- All Time

The default is **Last 7 Days**. All widgets update automatically when you change the filter.

## Caching

API responses are cached to keep the dashboard fast and reduce API calls. Multiple widgets sharing the same filters reuse a single cached response.

- Default TTL: 60 seconds
- Set to `0` to disable caching
- Uses your application's default cache driver

```php
// Cache for 5 minutes
SimplestatsPlugin::make()->cacheTtl(300)

// Disable caching entirely
SimplestatsPlugin::make()->cacheTtl(0)
```

## Self-Hosted

If you're running a self-hosted SimpleStats instance, point the plugin to your own API:

```php
SimplestatsPlugin::make()
    ->apiUrl('https://stats.yourdomain.com/api/v1')
```

Or via `.env`:

```env
SIMPLESTATS_API_URL=https://stats.yourdomain.com/api/v1
```

## Error Handling

The plugin handles API failures gracefully. If the API is unreachable or the token is invalid, widgets display empty states instead of throwing errors. Connection issues are logged as warnings for debugging.
