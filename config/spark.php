<?php

use App\Models\DataImport;
use App\Models\Project;
use App\Models\User;
use App\Providers\RouteServiceProvider;
use App\Services\SubscriptionService;
use Illuminate\Support\Number;

return [

    'dashboard_url' => RouteServiceProvider::HOME,

    /*
    |--------------------------------------------------------------------------
    | Spark Path
    |--------------------------------------------------------------------------
    |
    | This configuration option determines the URI at which the Spark billing
    | portal is available. You are free to change this URI to a value that
    | you prefer. You shall link to this location from your application.
    |
    */

    'path' => 'billing',

    /*
    |--------------------------------------------------------------------------
    | Spark Middleware
    |--------------------------------------------------------------------------
    |
    | These are the middleware that requests to the Spark billing portal must
    | pass through before being accepted. Typically, the default list that
    | is defined below should be suitable for most Laravel applications.
    |
    */

    'middleware' => ['web', 'auth'],

    /*
    |--------------------------------------------------------------------------
    | Branding
    |--------------------------------------------------------------------------
    |
    | These configuration values allow you to customize the branding of the
    | billing portal, including the primary color and the logo that will
    | be displayed within the billing portal. This logo value must be
    | the absolute path to an SVG logo within the local filesystem.
    |
    */

    'brand' => [
        'logo' => realpath(__DIR__.'/../public/svg/app_mark.svg'),
        'color' => 'bg-indigo-500',
    ],

    /*
    |--------------------------------------------------------------------------
    | Proration Behavior
    |--------------------------------------------------------------------------
    |
    | This value determines if charges are prorated when making adjustments
    | to a plan such as incrementing or decrementing the quantity of the
    | plan. This also determines proration behavior if changing plans.
    |
    */

    'prorates' => true,

    /*
    |--------------------------------------------------------------------------
    | Spark Date Format
    |--------------------------------------------------------------------------
    |
    | This date format will be utilized by Spark to format dates in various
    | locations within the billing portal, such as while showing invoice
    | dates. You should customize the format based on your own locale.
    |
    */

    'date_format' => 'F j, Y',

    /*
    |--------------------------------------------------------------------------
    | Spark Billables
    |--------------------------------------------------------------------------
    |
    | Below you may define billable entities supported by your Spark driven
    | application. You are free to have multiple billable entities which
    | can each define multiple subscription plans available for users.
    |
    | In addition to defining your billable entity, you may also define its
    | plans and the plan's features, including a short description of it
    | as well as a "bullet point" listing of its distinctive features.
    |
    */

    'billables' => [

        'user' => [
            'model' => User::class,

            'default_interval' => 'monthly',

            // always make sure that the plan structure is compatible with the plan eligibility check in the SparkServiceProvider...
            'plans' => [
                [
                    // free plan must be the first in the array
                    'name' => SubscriptionService::PLAN_FREE,
                    'icon' => 'heroicon-o-gift',
                    'color' => 'gray',
                    'short_description' => 'Simple analytics, no card required.',
                    'monthly_id' => 0,
                    'yearly_id' => 0,
                    'features' => [
                        Project::class => '1 Project',
                        'Events' => Number::abbreviate(5_000).' Monthly Events',
                        'MultiCurrency' => 'Multi Currency Support',
                        DataImport::class => 'Data Import',
                        'AiAssistant' => 'AI Assistant',
                    ],
                    'options' => [
                        Project::class => 1,
                        'Events' => 5_000,
                        'ApiRateLimit' => 200,
                        'MultiCurrency' => false,
                        DataImport::class => false,
                        'AiAssistant' => false,
                    ],
                    'archived' => true,
                ],
                [
                    'name' => SubscriptionService::PLAN_STARTER,
                    'icon' => 'heroicon-o-star',
                    'color' => 'info',
                    'short_description' => 'Great for smaller projects.',
                    'monthly_id' => env('SPARK_STARTER_MONTHLY_PLAN'),
                    'yearly_id' => env('SPARK_STARTER_YEARLY_PLAN'),
                    'features' => [
                        Project::class => '10 Projects',
                        'Events' => Number::abbreviate(100_000).' Monthly Events',
                        'MultiCurrency' => 'Multi Currency Support',
                        DataImport::class => 'Data Import',
                        'AiAssistant' => 'AI Assistant',
                    ],
                    'options' => [
                        Project::class => 10,
                        'Events' => 100_000,
                        'ApiRateLimit' => 500,
                        'MultiCurrency' => true,
                        DataImport::class => true,
                        'AiAssistant' => true,
                    ],
                    'archived' => false,
                ],
                [
                    'name' => SubscriptionService::PLAN_PLUS,
                    'icon' => 'heroicon-o-bolt',
                    'color' => 'success',
                    'short_description' => 'For ambitious, growing projects.',
                    'monthly_id' => env('SPARK_PLUS_MONTHLY_PLAN'),
                    'yearly_id' => env('SPARK_PLUS_YEARLY_PLAN'),
                    'features' => [
                        Project::class => 'Unlimited Projects',
                        'Events' => Number::abbreviate(200_000).' Monthly Events',
                        'MultiCurrency' => 'Multi Currency Support',
                        DataImport::class => 'Data Import',
                        'AiAssistant' => 'AI Assistant',
                    ],
                    'options' => [
                        Project::class => SubscriptionService::UNLIMITED_AMOUNT,
                        'Events' => 200_000,
                        'ApiRateLimit' => 1_000,
                        'MultiCurrency' => true,
                        DataImport::class => true,
                        'AiAssistant' => true,
                    ],
                    'archived' => false,
                    'promoted' => true,
                ],
                [
                    'name' => SubscriptionService::PLAN_BUSINESS,
                    'icon' => 'heroicon-o-building-office',
                    'color' => 'warning',
                    'short_description' => 'High-volume tracking at scale.',
                    'monthly_id' => env('SPARK_BUSINESS_MONTHLY_PLAN'),
                    'yearly_id' => env('SPARK_BUSINESS_YEARLY_PLAN'),
                    'features' => [
                        Project::class => 'Unlimited Projects',
                        'Events' => Number::abbreviate(500_000).' Monthly Events',
                        'MultiCurrency' => 'Multi Currency Support',
                        DataImport::class => 'Data Import',
                        'AiAssistant' => 'AI Assistant',
                    ],
                    'options' => [
                        Project::class => SubscriptionService::UNLIMITED_AMOUNT,
                        'Events' => 500_000,
                        'ApiRateLimit' => 2_500,
                        'MultiCurrency' => true,
                        DataImport::class => true,
                        'AiAssistant' => true,
                    ],
                    'archived' => false,
                ],
                [
                    'name' => SubscriptionService::PLAN_SCALE,
                    'icon' => 'heroicon-o-chart-bar-square',
                    'color' => 'warning',
                    'short_description' => 'Built for high-traffic products.',
                    'monthly_id' => env('SPARK_SCALE_MONTHLY_PLAN'),
                    'yearly_id' => env('SPARK_SCALE_YEARLY_PLAN'),
                    'features' => [
                        Project::class => 'Unlimited Projects',
                        'Events' => Number::abbreviate(1_000_000).' Monthly Events',
                        'MultiCurrency' => 'Multi Currency Support',
                        DataImport::class => 'Data Import',
                        'AiAssistant' => 'AI Assistant',
                    ],
                    'options' => [
                        Project::class => SubscriptionService::UNLIMITED_AMOUNT,
                        'Events' => 1_000_000,
                        'ApiRateLimit' => 3_000,
                        'MultiCurrency' => true,
                        DataImport::class => true,
                        'AiAssistant' => true,
                    ],
                    'archived' => false,
                ],
                [
                    'name' => SubscriptionService::PLAN_PREMIUM,
                    'icon' => 'heroicon-o-fire',
                    'color' => 'warning',
                    'short_description' => 'Power for data-heavy platforms.',
                    'monthly_id' => env('SPARK_PREMIUM_MONTHLY_PLAN'),
                    'yearly_id' => env('SPARK_PREMIUM_YEARLY_PLAN'),
                    'features' => [
                        Project::class => 'Unlimited Projects',
                        'Events' => Number::abbreviate(2_000_000).' Monthly Events',
                        'MultiCurrency' => 'Multi Currency Support',
                        DataImport::class => 'Data Import',
                        'AiAssistant' => 'AI Assistant',
                    ],
                    'options' => [
                        Project::class => SubscriptionService::UNLIMITED_AMOUNT,
                        'Events' => 2_000_000,
                        'ApiRateLimit' => 3_500,
                        'MultiCurrency' => true,
                        DataImport::class => true,
                        'AiAssistant' => true,
                    ],
                    'archived' => false,
                ],
                [
                    'name' => SubscriptionService::PLAN_ULTIMATE,
                    'icon' => 'heroicon-o-sparkles',
                    'color' => 'warning',
                    'short_description' => 'Near-limitless tracking headroom.',
                    'monthly_id' => env('SPARK_ULTIMATE_MONTHLY_PLAN'),
                    'yearly_id' => env('SPARK_ULTIMATE_YEARLY_PLAN'),
                    'features' => [
                        Project::class => 'Unlimited Projects',
                        'Events' => Number::abbreviate(5_000_000).' Monthly Events',
                        'MultiCurrency' => 'Multi Currency Support',
                        DataImport::class => 'Data Import',
                        'AiAssistant' => 'AI Assistant',
                    ],
                    'options' => [
                        Project::class => SubscriptionService::UNLIMITED_AMOUNT,
                        'Events' => 5_000_000,
                        'ApiRateLimit' => 4_000,
                        'MultiCurrency' => true,
                        DataImport::class => true,
                        'AiAssistant' => true,
                    ],
                    'archived' => false,
                ],
                [
                    'name' => SubscriptionService::PLAN_ENTERPRISE,
                    'icon' => 'heroicon-o-rocket-launch',
                    'color' => 'danger',
                    'short_description' => 'Analytics without any limits.',
                    'monthly_id' => env('SPARK_ENTERPRISE_MONTHLY_PLAN'),
                    'yearly_id' => env('SPARK_ENTERPRISE_YEARLY_PLAN'),
                    'features' => [
                        Project::class => 'Unlimited Projects',
                        'Events' => Number::abbreviate(10_000_000).' Monthly Events',
                        'MultiCurrency' => 'Multi Currency Support',
                        DataImport::class => 'Data Import',
                        'AiAssistant' => 'AI Assistant',
                    ],
                    'options' => [
                        Project::class => SubscriptionService::UNLIMITED_AMOUNT,
                        'Events' => 10_000_000,
                        'ApiRateLimit' => 5_000,
                        'MultiCurrency' => true,
                        DataImport::class => true,
                        'AiAssistant' => true,
                    ],
                    'archived' => false,
                ],
            ],
        ],
    ],

    'discount' => [
        'percent' => 50,
        'duration' => [
            'start' => '2026-11-23',
            'end' => '2026-11-30',
        ],
        'text' => '🎉 50% OFF ALL PLANS! Use code "BLACKWEEK2026" at checkout. 🎉',
        'link' => '#pricing',
    ],
];
