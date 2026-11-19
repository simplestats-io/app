---
description: "Configure which UTM parameters and tracking codes SimpleStats records for source, medium, campaign, term and content."
---

# Tracking Codes

In your [published](installation.html#configuration) _SimpleStats_ config file, you can set your tracking codes.

We already set some classical defaults for you, but you're free to completely change them to your needs. 

## Configuration

```php
//config/simplestats-client.php

    'tracking_codes' => [
        'source' => ['utm_source', 'ref'],
        'medium' => ['utm_medium', 'adGroup', 'adGroupId'],
        'campaign' => ['utm_campaign'],
        'term' => ['utm_term'],
        'content' => ['utm_content'],
    ],

```

The **tracking_codes** array is structured hierarchically, grouping parameters by tracking dimensions such as _source_, _medium_, _campaign_, _term_, and _content_. This organization allows for detailed filtering of your data by categorizing the origins and behaviors of the users, offering insights into the performance of your marketing efforts.

:::warning
Only the tracking params listed here are getting tracked and associated with a user registration!
:::

::: danger
The keys of the array must be named _source, medium, campaign, term and content_!
:::

## Valid Tracking URLs

So regarding the current config above, these URL params would be tracked and associated with the user at a registration:
```
https://example.com?utm_source=twitter&utm_medium=desktop
https://example.com?utm_source=twitter&utm_medium=mobile
https://example.com?ref=google&adGroup=banner
```

## Invalid Tracking URLs

And these would be not (cause they aren't defined in the config):
```
https://example.com?my_source=twitter&my_medium=desktop
https://example.com?my_ref=google&group=banner
```

## Payment Association with Tracking Codes

New payments are **exclusively** associated with the tracking codes (campaigns) from the user's registration. Individual payments cannot be associated with separate tracking codes. For example, if a user registers with a `utm_campaign` value of "facebook", all future payments by that user will be associated with the "facebook" campaign. This gives you the indication of how your campain is performing.
