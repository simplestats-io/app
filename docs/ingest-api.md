---
description: "Send visitors, users, logins, payments and custom events to SimpleStats over the HTTP Ingest API from any language or framework."
---

# Ingest API

The Ingest API is used to send tracking data (visitors, users, logins, payments, custom events) to SimpleStats. Even though the composer client package is dedicated to Laravel, the API basically works with **every client**! So if you'd like to use the tool, even if you're not using Laravel, you may just trigger the API requests manually.

::: tip
Looking to read your analytics data programmatically? Check out the [Query API](query-api.md).
:::

## Authentication

In order to use the API, you should authenticate your request by including your API token as a bearer token value:

`Authorization: Bearer API_TOKEN_HERE`

::: tip
You'll receive an API token after creating a new project, our you can find your existing API tokens inside the app on the project page.
:::

## Headers

Make sure you have the following content type headers are set on every request:

`Accept: application/json Content-Type: application/json`

## URI

_SimpleStats_ API is hosted on the following base URI:

`https://simplestats.io/api/v1/`

## Errors

_SimpleStats_ uses standard HTTP response codes to communicate the outcome of **API requests**. These codes offer immediate insight into whether a request has been successful, encountered an error, or requires additional action.

| Code   | Description                                                            |
|--------|------------------------------------------------------------------------|
| **200** | Everything is ok.                                                      |
| **400** | Valid data was given but the request has failed.                       |
| **401** | No valid API Key was given.                                            |
| **404** | The request resource could not be found.                               |
| **422** | The payload has missing required parameters or invalid data was given. |
| **429** | Too many attempts.                                                     |
| **500** | Request failed due to an internal error in _SimpleStats_.              |
| **503** | _SimpleStats_ is offline for maintenance.                              |

## Rate Limit

Rate limits depend on your subscription plan and are applied per project (API token) per minute. See [Plans and Limits](plans-and-limits.md) for details.

If you exceed your rate limit, the API will return a `429 Too Many Requests` response.

## Endpoints

At the moment, only the `create` endpoints are available.
But if the `id` already exists on our side, the record gets updated with the new data.

### Create Visitor

**HTTP Request**

`POST /api/v1/stats-visitor`

_Core parameters:_

[//]: <> (WARNING !!!!: THIS TABLE SHOULD BE IDENTICALL TO THE ONE ON THE "IMPORT USER" PAGE.)

| Key                | Type                 | Description                                                                            |
|--------------------|----------------------|----------------------------------------------------------------------------------------|
| **time**           | _string_ `required`  | The time when the user has registerd.<br/>Format: `Y-m-d H:i:s P`, before or equal `now` |
| **ip**             | _string_ `nullable`  | The `ip` of the user. _(does not get stored)_                                          |
| **user_agent**     | _string_ `nullable`  | The `user_agent` of the user. _(does not get stored)_                                  |
| **track_referer**  | _string_ `nullable`  | The http tracking `referer` of the user.                                               |
| **track_source**   | _string_ `nullable`  | The tracking `source` of the user.                                                     |
| **track_medium**   | _string_ `nullable`  | The tracking `medium` of the user.                                                     |
| **track_campaign** | _string_ `nullable`  | The tracking `campaign` of the user.                                                   |
| **track_term**     | _string_ `nullable`  | The tracking `term` of the user.                                                       |
| **track_content**  | _string_ `nullable`  | The tracking `content` of the user.                                                    |
| **page_entry**     | _string_ `nullable`  | The tracking `page_entry` of the user.                                                 |

::: details Optional parameters

| Key                  | Type                 | Description                                                                                                          |
|----------------------|----------------------|----------------------------------------------------------------------------------------------------------------------|
| **location_country** | _string_ `nullable`  | The location `country` of the user. (If the `ip` is set, we take the country that refers to it.)                     |
| **location_region**  | _string_ `nullable`  | The location `region` of the user. (If the `ip` is set, we take the region that refers to it.)                       |
| **location_city**    | _string_ `nullable`  | The location `city` of the user. (If the `ip` is set, we take the city that refers to it.)                           |
| **device_type**      | _string_ `nullable`  | The device `type` of the user. (If the `user_agent` is set, we take the device type that refers to it.)              |
| **device_platform**  | _string_ `nullable`  | The device `platform` (OS) of the user. (If the `user_agent` is set, we take the device platform that refers to it.) |
| **device_browser**   | _string_ `nullable`  | The device `browser` of the user. (If the `user_agent` is set, we take the device browser that refers to it.)        |
| **page_entry**       | _string_ `nullable`  | The page `entry` point of the user.                                                                                  |
| **properties**       | _object_ `nullable`  | Custom properties to group analytics by, as a `name: value` map (e.g. `{"ab_test": "B"}`). Values must be scalar. Max 50 names per request. See [custom properties](how-to-track-custom-properties.md). |
:::

**Payload**

```json
{
    "ip": "127.0.0.1",
    "user_agent": "Mozilla/5.0 (Linux; Android 13; Pixel 6) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/112.0.0.0 Mobile Safari/537.36",
    "track_source": "test",
    "time": "2024-04-04"
}
```

**Response**

```json
{
    "message": "Stats Visitor Request accepted.",
    "visitor_hash": "9a7c4f2e8b1d6a3c5e0f7b2d4a8c1e6f"
}
```

::: tip
If no `visitor_hash` is given, it gets generated from `time`, `ip` and `user_agent`. The returned `visitor_hash` can be used to reference this visitor in other endpoints (payments, custom events, custom properties).
:::

### Create User

**HTTP Request**

`POST /api/v1/stats-user`

_Core parameters:_

[//]: <> (WARNING !!!!: THIS TABLE SHOULD BE IDENTICALL TO THE ONE ON THE "IMPORT USER" PAGE.)

| Key                | Type                | Description                                                                              |
|--------------------|---------------------|------------------------------------------------------------------------------------------|
| **id**             | _mixed_ `required`  | The user id.                                                                             |
| **time**           | _string_ `required` | The time when the user has registerd.<br/>Format: `Y-m-d H:i:s P`, before or equal `now` |
| **ip**             | _string_ `nullable` | The `ip` of the user. _(does not get stored)_                                            |
| **user_agent**     | _string_ `nullable` | The `user_agent` of the user. _(does not get stored)_                                    |
| **track_referer**  | _string_ `nullable` | The http tracking `referer` of the user.                                                 |
| **track_source**   | _string_ `nullable` | The tracking `source` of the user.                                                       |
| **track_medium**   | _string_ `nullable` | The tracking `medium` of the user.                                                       |
| **track_campaign** | _string_ `nullable` | The tracking `campaign` of the user.                                                     |
| **track_term**     | _string_ `nullable` | The tracking `term` of the user.                                                         |
| **track_content**  | _string_ `nullable` | The tracking `content` of the user.                                                      |
| **page_entry**     | _string_ `nullable` | The tracking `page_entry` of the user.                                                   |

::: details Optional parameters

| Key                  | Type                 | Description                                                                                                          |
|----------------------|----------------------|----------------------------------------------------------------------------------------------------------------------|
| **location_country** | _string_ `nullable`  | The location `country` of the user. (If the `ip` is set, we take the country that refers to it.)                     |
| **location_region**  | _string_ `nullable`  | The location `region` of the user. (If the `ip` is set, we take the region that refers to it.)                       |
| **location_city**    | _string_ `nullable`  | The location `city` of the user. (If the `ip` is set, we take the city that refers to it.)                           |
| **device_type**      | _string_ `nullable`  | The device `type` of the user. (If the `user_agent` is set, we take the device type that refers to it.)              |
| **device_platform**  | _string_ `nullable`  | The device `platform` (OS) of the user. (If the `user_agent` is set, we take the device platform that refers to it.) |
| **device_browser**   | _string_ `nullable`  | The device `browser` of the user. (If the `user_agent` is set, we take the device browser that refers to it.)        |
| **page_entry**       | _string_ `nullable`  | The page `entry` point of the user.                                                                                  |
| **visitor_hash**     | _string_ `nullable`  | The `visitor_hash` of the visitor this registration converted from. Links the user to its prior visit (for visit-to-registration timing). |
| **properties**       | _object_ `nullable`  | Custom properties to group analytics by, as a `name: value` map (e.g. `{"ab_test": "B"}`). Values must be scalar. Max 50 names per request. See [custom properties](how-to-track-custom-properties.md). |
:::

**Payload**

```json
{
    "id": 1,
    "track_source": "test",
    "time": "2024-04-04"
}
```

**Response**

```json
{
    "message": "Stats User Request accepted.",
    "id": 1
}
```

### Create Login

**HTTP Request**

`POST /api/v1/stats-login`

**Parameters**

| Key               | Type                 | Description                                                                         |
|-------------------|----------------------|-------------------------------------------------------------------------------------|
| **stats_user_id** | _integer_ `required` | The id of the user trying to login.                                                 |
| **time**          | _string_ `required`  | The time when the user tries to login.<br/>Format: `Y-m-d H:i:s P`, before or equal `now` |

**Payload**

```json
{
    "stats_user_id": 1,
    "time": "2024-04-04"
}
```

**Response**

```json
{
    "message": "Stats Login Request accepted.",
    "id": 1
}
```

### Create Payment

**HTTP Request**

`POST /api/v1/stats-payment`

**Parameters**

[//]: <> (WARNING !!!!: THIS TABLE SHOULD BE IDENTICALL TO THE ONE ON THE "IMPORT PAYMENT" PAGE.)

| Key               | Type                 | Description                                                                            |
|-------------------|----------------------|----------------------------------------------------------------------------------------|
| **id**            | _mixed_ `required`   | The payment id.                                                                        |
| **stats_user_id** | _integer_ `required` | The user id associated with the payment.                                               |
| **visitor_hash**  | _string_ `nullable`  | The visitor hash.                                                                       |
| **time**          | _string_ `required`  | The time when the payment happened.<br/>Format: `Y-m-d H:i:s P`, before or equal `now` |
| **gross**         | _numeric_ `required` | The gross amount of the payment in cents.<br/>(e.g.: $1 = 100 Cent)                    |
| **net**           | _numeric_ `required` | The net amount of the payment in cents.<br/>(e.g.: $1 = 100 Cent).                     |
| **currency**      | _string_ `required`  | The **ISO-4217** currency code of the payment.<br/>(e.g.: EUR, USD, GBP ...)           |
| **subscription_interval** | _string_ `nullable` | The billing interval for subscription payments: `month` or `year`. Omit or `null` for one-time payments. |
| **subscription_plan** | _string_ `nullable` | The subscription plan the payment belongs to (free-form, e.g. `Starter`, `Business`). Omit or `null` for one-time payments. |

**Payload**

```json
{
    "id": "1",
    "stats_user_id": "1",
    "gross": 2000,
    "net": 1800,
    "currency": "EUR",
    "subscription_interval": "month",
    "subscription_plan": "Business",
    "time": "2024-04-04"
}
```

**Response**

```json
{
    "message": "Stats Payment Request accepted.",
    "id": "1"
}
```

### Create Custom Event

**HTTP Request**

`POST /api/v1/stats-custom-event`

**Parameters**

| Key               | Type                 | Description                                                                            |
|-------------------|----------------------|----------------------------------------------------------------------------------------|
| **id**            | _mixed_ `required`   | A unique identifier for this event.                                                    |
| **name**          | _string_ `required`  | The event name (e.g. "Button Clicked"). Events with the same name are grouped.         |
| **stats_user_id** | _mixed_ `nullable`   | The user id associated with the event.                                                 |
| **visitor_hash**  | _string_ `nullable`  | The visitor hash.                                                                       |
| **time**          | _string_ `required`  | The time when the event happened.<br/>Format: `Y-m-d H:i:s P`, before or equal `now`   |

**Payload**

```json
{
    "id": "evt-550e8400-e29b-41d4-a716-446655440000",
    "name": "Plan Upgraded",
    "stats_user_id": "1",
    "time": "2025-04-04"
}
```

**Response**

```json
{
    "message": "Stats Custom Event Request accepted.",
    "id": "evt-550e8400-e29b-41d4-a716-446655440000"
}
```

### Create Page Views

Page views are sent in batches. The [Laravel client](how-to-track-page-views.md) does this for you, use this endpoint only for your own integration. Page views do not count against the monthly event limit and have a rate limit bucket of their own, so they never slow down the other endpoints.

**HTTP Request**

`POST /api/v1/stats-views`

**Parameters**

| Key             | Type                 | Description                                                                                                                                                    |
|-----------------|----------------------|----------------------------------------------------------------------------------------------------------------------------------------------------------------|
| **batch_id**    | _uuid_ `required`    | Generated once per batch and sent again unchanged on every retry. A batch that was already stored is not counted twice.                                         |
| **views**       | _array_ `required`   | 1 to 1,000 entries. Invalid entries are skipped one by one, the valid ones are stored.                                                                         |
| **views.\*.h** | _string_ `required`  | The visitor hash, exactly as sent with [Create Visitor](#create-visitor). Views of a hash the API never received are dropped after a retry window of about two hours. |
| **views.\*.t** | _integer_ `required` | The minute of the view as Unix timestamp in seconds (UTC). Older than 48 hours or in the future is clamped.                                                    |
| **views.\*.n** | _integer_ `required` | The number of views of this page in this minute (1 to 1,000).                                                                                                  |
| **views.\*.p** | _string_ `required`  | The page path, starting with `/`. Query string and fragment are dropped, a trailing slash is removed, case is kept.                                            |
| **meta.mode**   | _string_ `optional`  | `worker` (bundled) or `direct` (one view per request), for diagnostics.                                                                                        |

Every view is counted, including the first one of a visitor: the visitor event itself is no page view. A visitor counts at most 500 views per day.

**Payload**

```json
{
    "batch_id": "9d6f1c1e-7c40-4f0a-b1a2-3e5d8a6b7c90",
    "views": [
        {"h": "a3f1c09e5b7d42e1a3f1c09e5b7d42e1", "t": 1794687300, "n": 2, "p": "/pricing"},
        {"h": "0b9e77aa12cd4f5560b9e77aa12cd4f5", "t": 1794687360, "n": 1, "p": "/"}
    ],
    "meta": {"mode": "worker"}
}
```

**Response**

```json
{
    "message": "Stats Views Request accepted.",
    "batch_id": "9d6f1c1e-7c40-4f0a-b1a2-3e5d8a6b7c90",
    "accepted": 2,
    "rejected": 0,
    "duplicate": false
}
```

Once the monthly event limit is reached, the response is `{"message": "Monthly event limit reached.", "pause": 3600}`: stop sending views for `pause` seconds.

### Create Custom Property

**HTTP Request**

`POST /api/v1/stats-custom-properties`

**Parameters**

| Key               | Type                 | Description                                                                                                                  |
|-------------------|----------------------|------------------------------------------------------------------------------------------------------------------------------|
| **properties**    | _object_ `required`  | Custom properties to group analytics by, as a `name: value` map (e.g. `{"ab_test": "B"}`). Values must be scalar. Max 50 names per request. |
| **stats_user_id** | _mixed_ `nullable`   | The user id to attach the properties to. Required without `visitor_hash`.                                                    |
| **visitor_hash**  | _string_ `nullable`  | The visitor hash to attach the properties to. Required without `stats_user_id`.                                              |
| **time**          | _string_ `required`  | The time the properties were current.<br/>Format: `Y-m-d H:i:s P`, before or equal `now`                                     |

**Payload**

```json
{
    "stats_user_id": "1",
    "properties": {
        "ab_test": "B"
    },
    "time": "2026-04-04"
}
```

**Response**

```json
{
    "message": "Stats Custom Property Request accepted.",
    "id": "1"
}
```
