---
description: "SimpleStats tracks every successful Laravel login automatically via the Login event. Learn how to use your own login event instead."
---

# How to track a new login

We **automatically** track every successful login to your application by tapping into Laravel's built-in `Illuminate\Auth\Events\Login` event. This hands-off approach ensures that you don't need to track these events manually. Leveraging this data, our dashboard provides the ability to filter user activities over a specified time period, enabling a comprehensive analysis of user behavior trends across _days, weeks, and months_. This detailed insight aids in understanding and optimizing user engagement over time.

## Configuration

If you for any reason do **not** use the default Login event from Laravel, you may define your own here:

```php
//config/simplestats-client.php

    'tracking_types' => [
        // ...

        'login' => [
            'event' => App\Events\CustomLogin::class,
        ],
        
        // ...
    ],

```
