---
description: "Import your historical users and payments into SimpleStats from CSV or Excel files, so your analytics start with your full history."
---

# How to import existing data

Integrating _SimpleStats_ into your existing application doesn't mean leaving behind your historical data. Recognizing the value of your application's past insights, we've developed the **Data Import** feature. This functionality enables a smooth transition by allowing you to seamlessly **import** your **existing data** into _SimpleStats_, ensuring that you retain access to all historical metrics and insights. 

::: info
The Data Import feature is not available on the free subscription plan.
:::

## Supported Formats

The Data Import supports the following file formats: _**xls, xlsx**_ and _**csv**_.

## Import Capabilities

The import feature is designed to manage **substantial** volumes of data efficiently. Therefore, unless you approach the limits of your subscription, we encourage you to import the **entirety** of your data. This capability ensures that you can leverage the full extent of your **historical insights** without reservations.

## Importing User Data

Though it is not mandatory, it's highly recommended to **first** import the **user data** and only then the corresponding payment data. To start a new import, you have to provide a [supported file format](#supported-formats) containing the user data to import.

### Allowed User Fields

The following fields are allowed:

[//]: <> (WARNING !!!!: THIS TABLE SHOULD BE IDENTICALL TO THE ONE ON THE "API DOCS" PAGE.)

_Core fields:_

| Key                  | Type                | Description                                                                            |
|----------------------|---------------------|----------------------------------------------------------------------------------------|
| **id**               | _mixed_ `required`  | The user id.                                                                           |
| **time**             | _string_ `required` | The time when the user has registerd.<br/>Format: `Y-m-d H:i:s`, before or equal `now` |

::: details Optional fields

| Key                  | Type                 | Description                                                                                                          |
|----------------------|----------------------|----------------------------------------------------------------------------------------------------------------------|
| **ip**               | _string_ `nullable`  | The `ip` of the user. _(does not get stored)_                                                                        |
| **user_agent**       | _string_ `nullable`  | The `user_agent` of the user. _(does not get stored)_                                                                |
| **track_referer**    | _string_ `nullable`  | The tracking `referer` of the user.                                                                                  |
| **track_source**     | _string_ `nullable`  | The tracking `source` of the user.                                                                                   |
| **track_medium**     | _string_ `nullable`  | The tracking `medium` of the user.                                                                                   |
| **track_campaign**   | _string_ `nullable`  | The tracking `campaign` of the user.                                                                                 |
| **track_term**       | _string_ `nullable`  | The tracking `term` of the user.                                                                                     |
| **track_content**    | _string_ `nullable`  | The tracking `content` of the user.                                                                                  |
| **location_country** | _string_ `nullable`  | The location `country` of the user. (If the `ip` is set, we take the country that refers to it.)                     |
| **location_region**  | _string_ `nullable`  | The location `region` of the user. (If the `ip` is set, we take the region that refers to it.)                       |
| **location_city**    | _string_ `nullable`  | The location `city` of the user. (If the `ip` is set, we take the city that refers to it.)                           |
| **device_type**      | _string_ `nullable`  | The device `type` of the user. (If the `user_agent` is set, we take the device type that refers to it.)              |
| **device_platform**  | _string_ `nullable`  | The device `platform` (OS) of the user. (If the `user_agent` is set, we take the device platform that refers to it.) |
| **device_browser**   | _string_ `nullable`  | The device `browser` of the user. (If the `user_agent` is set, we take the device browser that refers to it.)        |
| **page_entry**       | _string_ `nullable`  | The page `entry` point of the user.                                                                                  |
:::

Here is an **example** of how such an SQL query may look like:

::: code-group

```sql [MySQL]
SELECT
    id,
    DATE_FORMAT(created_at, '%Y-%m-%d %H:%i:%s') AS `time`
FROM
    users;
```

```sql [PostgreSQL]
SELECT
    id,
    to_char(created_at, 'YYYY-MM-DD HH24:MI:SS') AS "time"
FROM
    users;
```

:::

### With Condition

Sometimes you may not want to count all users as "valid" registrations. So you may only export the users which pass a certain condition, such as the user has verified their email address.

The query could look like this:

::: code-group

```sql [MySQL]
SELECT
    id,
    DATE_FORMAT(created_at, '%Y-%m-%d %H:%i:%s') AS `time`
FROM
    users
WHERE
    email_verified_at IS NOT NULL;
```

```sql [PostgreSQL]
SELECT
    id,
    to_char(created_at, 'YYYY-MM-DD HH24:MI:SS') AS "time"
FROM
    users
WHERE
    email_verified_at IS NOT NULL;
```

:::

Now export the output of the query, save the file and then you can **upload** the file inside the app to start a new import!

::: info
Please note, the provided queries serve merely as **examples**. Your actual table structures and data might vary significantly. The implementation is completely up to you!
:::

::: warning
The first row of your import file must be a **heading row** (_a row in which each cell contains the key of the [allowed fields](#allowed-user-fields)_)!
:::

### Simulated Natural Login Behavior (SNLB)

In many cases, historical login data for users isn't readily available. Without logins, activity metrics like DAU/WAU/MAU stay empty for the imported period. To address this, your user import file may optionally include a `last_known_activity` column. This field could correspond to the `updated_at` attribute, a `last_login` field, or any similar date in your `users` table that best approximates the user's most recent activity.

By supplying this information, we generate simulated login entries to mimic natural login patterns, based on the assumption that each user logged in at least **once a week** from their registration date to their _last known activity_. This provides a richer, more accurate representation of your historical user engagement over time.

| Key                     | Type                | Description                                                                                              |
|-------------------------|---------------------|----------------------------------------------------------------------------------------------------------|
| **last_known_activity** | _string_ `nullable` | The time of the user's most recent activity.<br/>Format: `Y-m-d H:i:s`, must be equal or after `time` |

How it works:

- The first simulated login is placed at the registration `time`, followed by logins at least once a week with natural variation, and a final login exactly at `last_known_activity`.
- Values in the future are clamped to the current time.
- Users that already have login data are skipped, so re-imports won't create duplicates.
- Rolling back the import also removes the generated logins.

Here is an **example** of how the SQL query from above may look like with SNLB:

::: code-group

```sql [MySQL]
SELECT
    id,
    DATE_FORMAT(created_at, '%Y-%m-%d %H:%i:%s') AS `time`,
    DATE_FORMAT(updated_at, '%Y-%m-%d %H:%i:%s') AS `last_known_activity`
FROM
    users;
```

```sql [PostgreSQL]
SELECT
    id,
    to_char(created_at, 'YYYY-MM-DD HH24:MI:SS') AS "time",
    to_char(updated_at, 'YYYY-MM-DD HH24:MI:SS') AS "last_known_activity"
FROM
    users;
```

:::

## Importing Payment Data

After you've imported your user data, you may start another import for your payment data. Again you have to provide a [supported file format](#supported-formats) containing the payment data to import.

### Allowed Payment Fields

The following fields are allowed:

[//]: <> (WARNING !!!!: THIS TABLE SHOULD BE IDENTICALL TO THE ONE ON THE "API DOCS" PAGE.)

| Key               | Type                 | Description                                                                          |
|-------------------|----------------------|--------------------------------------------------------------------------------------|
| **id**            | _mixed_ `required`   | The payment id.                                                                      |
| **stats_user_id** | _integer_ `required` | The user id associated with the payment.                                             |
| **time**          | _string_ `required`  | The time when the payment happened.<br/>Format: `Y-m-d H:i:s`, before or equal `now` |
| **gross**         | _numeric_ `required` | The gross amount of the payment in cents.<br/>(e.g.: $1 = 100 Cent)                  |
| **net**           | _numeric_ `required` | The net amount of the payment in cents.<br/>(e.g.: $1 = 100 Cent).                   |
| **currency**      | _string_ `required`  | The **ISO-4217** currency code of the payment.<br/>(e.g.: EUR, USD, GBP ...)         |
| **subscription_interval** | _string_ `nullable` | The billing interval for subscription payments: `month` or `year`. Omit or `null` for one-time payments. |
| **subscription_plan** | _string_ `nullable` | The subscription plan the payment belongs to (free-form, e.g. `Starter`, `Business`). Omit or `null` for one-time payments. |

Assuming the table is called `transactions`, here is an **example** of how such an SQL query may look like:

::: code-group

```sql [MySQL]
SELECT
    id,
    user_id AS stats_user_id,
    total AS gross,
    total - tax AS net,
    'USD' AS currency,
    DATE_FORMAT(created_at, '%Y-%m-%d %H:%i:%s') AS `time`
FROM
    transactions;
```

```sql [PostgreSQL]
SELECT
    id,
    user_id AS stats_user_id,
    total AS gross,
    total - tax AS net,
    'USD' AS currency,
    to_char(created_at, 'YYYY-MM-DD HH24:MI:SS') AS "time"
FROM
    transactions;
```

:::

### With Condition

Sometimes you may not want to count all payments as "valid". So you may only export the payments which pass a certain condition, such as having a "completed" status.

The query could look like this:

::: code-group

```sql [MySQL]
SELECT
    id,
    user_id AS stats_user_id,
    total AS gross,
    total - tax AS net,
    'USD' AS currency,
    DATE_FORMAT(created_at, '%Y-%m-%d %H:%i:%s') AS `time`
FROM
    transactions
WHERE
    status = 'completed';
```

```sql [PostgreSQL]
SELECT
    id,
    user_id AS stats_user_id,
    total AS gross,
    total - tax AS net,
    'USD' AS currency,
    to_char(created_at, 'YYYY-MM-DD HH24:MI:SS') AS "time"
FROM
    transactions
WHERE
    status = 'completed';
```

:::

Now export the output of the query, save the file and then you can **upload** the file inside the app to start a new import!

::: info
Please note, the provided queries serve merely as **examples**. Your actual table structures and data might vary significantly. The implementation is completely up to you!
:::

::: warning
The first row of your import file must be a **heading row** (_a row in which each cell contains the key of the [allowed fields](#allowed-payment-fields)_)!
:::

::: danger
Remember that **Gross** and **Net** must be stated in cents!
:::

## Importing Visitor Data

If you used another analytics tool before, you can bring your historical visitors over as well. Each row of the import file is one visitor.

### Allowed Visitor Fields

| Key                  | Type                | Description                                                                                                                          |
|----------------------|---------------------|--------------------------------------------------------------------------------------------------------------------------------------|
| **time**             | _string_ `required` | The time of the visit.<br/>Format: `Y-m-d H:i:s`, before or equal `now`                                                              |
| **visitor_hash**     | _string_ `nullable` | A unique id of the visitor. Required if you want to [import page views](#importing-page-view-data) for this visitor afterwards. |

::: details Optional fields

| Key                  | Type                 | Description                                                                                                          |
|----------------------|----------------------|----------------------------------------------------------------------------------------------------------------------|
| **ip**               | _string_ `nullable`  | The `ip` of the visitor. _(does not get stored)_                                                                     |
| **user_agent**       | _string_ `nullable`  | The `user_agent` of the visitor. _(does not get stored)_                                                             |
| **track_referer**    | _string_ `nullable`  | The tracking `referer` of the visitor.                                                                               |
| **track_source**     | _string_ `nullable`  | The tracking `source` of the visitor.                                                                                |
| **track_medium**     | _string_ `nullable`  | The tracking `medium` of the visitor.                                                                                |
| **track_campaign**   | _string_ `nullable`  | The tracking `campaign` of the visitor.                                                                              |
| **track_term**       | _string_ `nullable`  | The tracking `term` of the visitor.                                                                                  |
| **track_content**    | _string_ `nullable`  | The tracking `content` of the visitor.                                                                               |
| **location_country** | _string_ `nullable`  | The location `country` of the visitor. (If the `ip` is set, we take the country that refers to it.)                  |
| **location_region**  | _string_ `nullable`  | The location `region` of the visitor. (If the `ip` is set, we take the region that refers to it.)                    |
| **location_city**    | _string_ `nullable`  | The location `city` of the visitor. (If the `ip` is set, we take the city that refers to it.)                        |
| **device_type**      | _string_ `nullable`  | The device `type` of the visitor. (If the `user_agent` is set, we take the device type that refers to it.)           |
| **device_platform**  | _string_ `nullable`  | The device `platform` (OS) of the visitor. (If the `user_agent` is set, we take the device platform that refers to it.) |
| **device_browser**   | _string_ `nullable`  | The device `browser` of the visitor. (If the `user_agent` is set, we take the device browser that refers to it.)     |
| **page_entry**       | _string_ `nullable`  | The page the visitor entered your app on, e.g. `/pricing`.                                                          |
:::

A visitor whose `visitor_hash` is already known (tracked live or imported before) is skipped, so a re-import never changes existing data.

::: info
Imported visitors count as events against your plan, just like tracked ones.
:::

## Importing Page View Data

Page views belong to a visitor. Therefore, import your [visitors](#importing-visitor-data) **first**, then the page views. Each row is one page of one visitor.

### Allowed Page View Fields

| Key              | Type                 | Description                                                                                  |
|------------------|----------------------|----------------------------------------------------------------------------------------------|
| **visitor_hash** | _string_ `required`  | The `visitor_hash` of an already imported or tracked visitor.                                |
| **page**         | _string_ `required`  | The path of the viewed page, e.g. `/pricing`. Query strings and fragments are removed.       |
| **views**        | _integer_ `nullable` | How often the visitor viewed this page. Defaults to `1`.                                     |
| **time**         | _string_ `nullable`  | The time of the last view. Defaults to the time of the visitor.<br/>Format: `Y-m-d H:i:s` |

Rows of unknown visitors are skipped. Page views do not count as events against your plan.

::: warning
A page view import cannot be rolled back on its own. Rolling back the corresponding **visitor** import removes its page views as well.
:::

## Importing a Fathom Analytics Export

Coming from [Fathom Analytics](https://usefathom.com)? You can upload a Fathom export as it is, no need to reshape it. In the **Switch from another Tool** section of the Data Imports page, select your project on the **Fathom Analytics** card, choose the CSV file and start the import.

### Creating the Export in Fathom

In Fathom, create a new export with the following settings:

| Setting           | Value                                                                                                     |
|-------------------|-----------------------------------------------------------------------------------------------------------|
| **Data Type**     | Pageviews                                                                                                 |
| **Timezone**      | The timezone of your _SimpleStats_ team                                                                   |
| **Date Grouping** | Daily or Hourly                                                                                           |
| **Dimensions**    | Page, Referrer, UTM Parameters<br/>_(optional: AI Source, Country, Region, City, Browser, Device Type, Operating System)_ |
| **Metrics**       | Pageviews, Entries, Page Visitors                                                                         |

### How the Data Is Imported

Fathom only exports totals (e.g. "on October 15, 12 visitors came from laravel-news.com and entered on `/blog`"), not individual visitors. We therefore rebuild one visitor per entry, with its referrer, UTM parameters and entry page, and distribute the page views among these visitors.

As a result, the following numbers match your Fathom dashboard per day (or per hour with hourly grouping):

- visitors
- page views and viewers per page
- entry pages
- referrers and UTM parameters

The time of day within a day, and with it the **bounce rate**, is an approximation.

The import creates visitors and page views, so it counts like a [visitor import](#importing-visitor-data) against your plan. Rolling back a Fathom import removes all of its visitors and page views.
