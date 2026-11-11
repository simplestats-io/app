<?php

use App\Providers\AppServiceProvider;
use App\Providers\AuthServiceProvider;
use App\Providers\DbServiceProvider;
use App\Providers\EventServiceProvider;
use App\Providers\Filament\AdminPanelProvider;
use App\Providers\FortifyServiceProvider;
use App\Providers\HorizonServiceProvider;
use App\Providers\JetstreamServiceProvider;
use App\Providers\RouteServiceProvider;
use App\Providers\SocialstreamServiceProvider;
use App\Providers\SparkServiceProvider;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\ServiceProvider;
use Lab404\Impersonate\ImpersonateServiceProvider;

return [

    /*
    |--------------------------------------------------------------------------
    | Mail Settings
    |--------------------------------------------------------------------------
    |
    | Mail addresses from which intern app and admin mails are sent from.
    |
    */

    'app_mail' => env('APP_MAIL', 'simplestats@laracraft.tech'),
    'admin_mail' => env('ADMIN_MAIL', 'admin@laracraft.tech'),

    /*
    |--------------------------------------------------------------------------
    | Branding Settings
    |--------------------------------------------------------------------------
    |
    | Hardcoded on purpose, never read from env: the name and logo are
    | trademarks (resources/markdown/trademark.md), so changing them is a
    | source code change, not a setting.
    |
    */

    'branding' => [
        'app_mark' => null,
        'app_logo' => null,
        'app_logo_mail' => '/images/logo.png',
        'app_background' => '/images/side_bg.jpg',
    ],

    /*
    |--------------------------------------------------------------------------
    | Auto Subscription Users
    |--------------------------------------------------------------------------
    |
    | A comma separated list of user ids how automatically will get an enterprise subscription plan.
    | SaaS only, self-hosted installations always run on the unlimited Self-Hosted plan.
    |
    */

    'plan_auto_max_users' => explode(',', env('PLAN_AUTO_MAX_USERS', '')),

    /*
    |--------------------------------------------------------------------------
    | Self-Hosted API Rate Limit
    |--------------------------------------------------------------------------
    |
    | Requests per minute and project on the ingest/query API of a self-hosted installation.
    |
    */

    'self_hosted_api_rate_limit' => env('SELF_HOSTED_API_RATE_LIMIT', 10000),

    /*
    |--------------------------------------------------------------------------
    | Public Docs URL
    |--------------------------------------------------------------------------
    |
    | Self-hosted installations don't serve the docs themselves and redirect /docs here.
    |
    */

    'public_docs_url' => env('PUBLIC_DOCS_URL', 'https://simplestats.io/docs'),

    /*
    |--------------------------------------------------------------------------
    | Aggregation Settings
    |--------------------------------------------------------------------------
    |
    | Whether all stats are calculated always from scratch or the aggregation table is used.
    | We highly recommend leaving true here. Maybe set to false for local debugging.
    |
    */

    'stats_aggregation_enabled' => env('STATS_AGGREGATION_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Application Type
    |--------------------------------------------------------------------------
    |
    | Here you can define your app type.
    |
    */

    'app_type' => env('APP_TYPE', 'default'),

    /*
    |--------------------------------------------------------------------------
    | Application Name
    |--------------------------------------------------------------------------
    |
    | This value is the name of your application. This value is used when the
    | framework needs to place the application's name in a notification or
    | any other location as required by the application or its packages.
    |
    | Not read from env on purpose, see the branding section. The frontend
    | counterpart is VITE_APP_NAME in vite.config.js.
    |
    */

    'name' => 'SimpleStats',

    /*
    |--------------------------------------------------------------------------
    | Application Environment
    |--------------------------------------------------------------------------
    |
    | This value determines the "environment" your application is currently
    | running in. This may determine how you prefer to configure various
    | services the application utilizes. Set this in your ".env" file.
    |
    */

    'env' => env('APP_ENV', 'production'),

    /*
    |--------------------------------------------------------------------------
    | Application Debug Mode
    |--------------------------------------------------------------------------
    |
    | When your application is in debug mode, detailed error messages with
    | stack traces will be shown on every error that occurs within your
    | application. If disabled, a simple generic error page is shown.
    |
    */

    'debug' => (bool) env('APP_DEBUG', false),

    /*
    |--------------------------------------------------------------------------
    | Application URL
    |--------------------------------------------------------------------------
    |
    | This URL is used by the console to properly generate URLs when using
    | the Artisan command line tool. You should set this to the root of
    | your application so that it is used when running Artisan tasks.
    |
    */

    'url' => env('APP_URL', 'http://localhost'),

    'asset_url' => env('ASSET_URL'),

    /*
    |--------------------------------------------------------------------------
    | Application Timezone
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default timezone for your application, which
    | will be used by the PHP date and date-time functions. We have gone
    | ahead and set this to a sensible default for you out of the box.
    |
    */

    'timezone' => 'UTC',

    /*
    |--------------------------------------------------------------------------
    | Application Locale Configuration
    |--------------------------------------------------------------------------
    |
    | The application locale determines the default locale that will be used
    | by the translation service provider. You are free to set this value
    | to any of the locales which will be supported by the application.
    |
    */

    'locale' => 'en',

    /*
    |--------------------------------------------------------------------------
    | Application Fallback Locale
    |--------------------------------------------------------------------------
    |
    | The fallback locale determines the locale to use when the current one
    | is not available. You may change the value to correspond to any of
    | the language folders that are provided through your application.
    |
    */

    'fallback_locale' => 'en',

    /*
    |--------------------------------------------------------------------------
    | Faker Locale
    |--------------------------------------------------------------------------
    |
    | This locale will be used by the Faker PHP library when generating fake
    | data for your database seeds. For example, this will be used to get
    | localized telephone numbers, street address information and more.
    |
    */

    'faker_locale' => 'en_US',

    /*
    |--------------------------------------------------------------------------
    | Encryption Key
    |--------------------------------------------------------------------------
    |
    | This key is used by the Illuminate encrypter service and should be set
    | to a random, 32 character string, otherwise these encrypted strings
    | will not be safe. Please do this before deploying an application!
    |
    */

    'key' => env('APP_KEY'),

    'cipher' => 'AES-256-CBC',

    /*
    |--------------------------------------------------------------------------
    | Maintenance Mode Driver
    |--------------------------------------------------------------------------
    |
    | These configuration options determine the driver used to determine and
    | manage Laravel's "maintenance mode" status. The "cache" driver will
    | allow maintenance mode to be controlled across multiple machines.
    |
    | Supported drivers: "file", "cache"
    |
    */

    'maintenance' => [
        'driver' => 'file',
        // 'store' => 'redis',
    ],

    /*
    |--------------------------------------------------------------------------
    | Autoloaded Service Providers
    |--------------------------------------------------------------------------
    |
    | The service providers listed here will be automatically loaded on the
    | request to your application. Feel free to add your own services to
    | this array to grant expanded functionality to your applications.
    |
    */

    'providers' => ServiceProvider::defaultProviders()->merge([
        /*
         * Package Service Providers...
         */

        /*
         * Application Service Providers...
         */
        AppServiceProvider::class,
        AuthServiceProvider::class,
        DbServiceProvider::class,
        // App\Providers\BroadcastServiceProvider::class,
        EventServiceProvider::class,
        HorizonServiceProvider::class,
        SparkServiceProvider::class,
        ...env('APP_TYPE') === 'saas' ? [AdminPanelProvider::class] : [],
        RouteServiceProvider::class,
        SocialstreamServiceProvider::class,
        //        App\Providers\TelescopeServiceProvider::class,
        FortifyServiceProvider::class,
        JetstreamServiceProvider::class,
        ImpersonateServiceProvider::class,
    ])->toArray(),

    /*
    |--------------------------------------------------------------------------
    | Class Aliases
    |--------------------------------------------------------------------------
    |
    | This array of class aliases will be registered when this application
    | is started. However, feel free to register as many as you wish as
    | the aliases are "lazy" loaded so they don't hinder performance.
    |
    */

    'aliases' => Facade::defaultAliases()->merge([
        // 'Example' => App\Facades\Example::class,
    ])->toArray(),

    /*
    |--------------------------------------------------------------------------
    | Local/Test/Demo User Credentials
    |--------------------------------------------------------------------------
    |
    */

    'test' => [
        'user' => [
            'name' => 'John Doe',
            'email' => 'john.doe@example.com',
            'password' => 'password',
        ],
    ],
];
