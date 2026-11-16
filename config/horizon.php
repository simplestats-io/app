<?php

use App\Jobs\ResolvePendingStatsViews;
use App\Jobs\TrackStatsCustomEvent;
use App\Jobs\TrackStatsCustomProperties;
use App\Jobs\TrackStatsPayment;
use App\Jobs\TrackStatsUser;
use App\Jobs\TrackStatsViews;
use App\Jobs\TrackStatsVisitor;
use Illuminate\Support\Str;

return [

    /*
    |--------------------------------------------------------------------------
    | Horizon Domain
    |--------------------------------------------------------------------------
    |
    | This is the subdomain where Horizon will be accessible from. If this
    | setting is null, Horizon will reside under the same domain as the
    | application. Otherwise, this value will serve as the subdomain.
    |
    */

    'domain' => env('HORIZON_DOMAIN'),

    /*
    |--------------------------------------------------------------------------
    | Horizon Path
    |--------------------------------------------------------------------------
    |
    | This is the URI path where Horizon will be accessible from. Feel free
    | to change this path to anything you like. Note that the URI will not
    | affect the paths of its internal API that aren't exposed to users.
    |
    */

    'path' => env('HORIZON_PATH', 'horizon'),

    /*
    |--------------------------------------------------------------------------
    | Horizon Redis Connection
    |--------------------------------------------------------------------------
    |
    | This is the name of the Redis connection where Horizon will store the
    | meta information required for it to function. It includes the list
    | of supervisors, failed jobs, job metrics, and other information.
    |
    */

    'use' => 'default',

    /*
    |--------------------------------------------------------------------------
    | Horizon Redis Prefix
    |--------------------------------------------------------------------------
    |
    | This prefix will be used when storing all Horizon data in Redis. You
    | may modify the prefix when you are running multiple installations
    | of Horizon on the same server so that they don't have problems.
    |
    */

    'prefix' => env(
        'HORIZON_PREFIX',
        Str::slug(env('APP_NAME', 'laravel'), '_').'_'.env('APP_ENV', 'local').'_horizon:'
    ),

    /*
    |--------------------------------------------------------------------------
    | Horizon Route Middleware
    |--------------------------------------------------------------------------
    |
    | These middleware will get attached onto each Horizon route, giving you
    | the chance to add your own middleware to this list or change any of
    | the existing middleware. Or, you can simply stick with this list.
    |
    */

    'middleware' => ['web'],

    /*
    |--------------------------------------------------------------------------
    | Queue Wait Time Thresholds
    |--------------------------------------------------------------------------
    |
    | This option allows you to configure when the LongWaitDetected event
    | will be fired. Every connection / queue combination may have its
    | own, unique threshold (in seconds) before this event is fired.
    |
    */

    'waits' => [
        'redis:default' => 60,
        'redis:ingest' => 60,
        'redis:low' => 300,
    ],

    /*
    |--------------------------------------------------------------------------
    | Job Trimming Times
    |--------------------------------------------------------------------------
    |
    | Here you can configure for how long (in minutes) you desire Horizon to
    | persist the recent and failed jobs. Typically, recent jobs are kept
    | for one hour while all failed jobs are stored for an entire week.
    |
    */

    'trim' => [
        'recent' => 60,
        'pending' => 60,
        'completed' => 60,
        'recent_failed' => 1440,
        'failed' => 1440,
        'monitored' => 1440,
    ],

    /*
    |--------------------------------------------------------------------------
    | Silenced Jobs
    |--------------------------------------------------------------------------
    |
    | Silencing a job will instruct Horizon to not place the job in the list
    | of completed jobs within the Horizon dashboard. This setting may be
    | used to fully remove any noisy jobs from the completed jobs list.
    |
    */

    'silenced' => [
        TrackStatsUser::class,
        TrackStatsVisitor::class,
        TrackStatsPayment::class,
        TrackStatsCustomEvent::class,
        TrackStatsCustomProperties::class,
        TrackStatsViews::class,
        ResolvePendingStatsViews::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Metrics
    |--------------------------------------------------------------------------
    |
    | Here you can configure how many snapshots should be kept to display in
    | the metrics graph. This will get used in combination with Horizon's
    | `horizon:snapshot` schedule to define how long to retain metrics.
    |
    */

    'metrics' => [
        'trim_snapshots' => [
            'job' => 168,
            'queue' => 168,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Fast Termination
    |--------------------------------------------------------------------------
    |
    | When this option is enabled, Horizon's "terminate" command will not
    | wait on all of the workers to terminate unless the --wait option
    | is provided. Fast termination can shorten deployment delay by
    | allowing a new instance of Horizon to start while the last
    | instance will continue to terminate each of its workers.
    |
    */

    'fast_termination' => true,

    /*
    |--------------------------------------------------------------------------
    | Memory Limit (MB)
    |--------------------------------------------------------------------------
    |
    | This value describes the maximum amount of memory the Horizon master
    | supervisor may consume before it is terminated and restarted. For
    | configuring these limits on your workers, see the next section.
    |
    */

    'memory_limit' => 128,

    /*
    |--------------------------------------------------------------------------
    | Queue Worker Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may define the queue worker settings used by your application
    | in all environments. These supervisors and settings handle all your
    | queued jobs and will be provisioned by Horizon during deployment.
    |
    */

    'defaults' => [
        'supervisor-default' => [
            'connection' => 'redis',
            'queue' => ['default'],
            'balance' => 'auto',
            'minProcesses' => (int) env('HORIZON_DEFAULT_MIN_PROCESSES', 2),
            'maxProcesses' => (int) env('HORIZON_DEFAULT_MAX_PROCESSES', 5),
            'timeout' => 60,
            'retry_after' => 90,
            'tries' => 3,
            'memory' => 256,
        ],
        'supervisor-low' => [
            'connection' => 'redis',
            'queue' => ['low'],
            'balance' => 'simple',
            'minProcesses' => (int) env('HORIZON_LOW_MIN_PROCESSES', 1),
            'maxProcesses' => (int) env('HORIZON_LOW_MAX_PROCESSES', 1),
            'timeout' => 3600,
            'retry_after' => 3700,
            'tries' => 1,
            'memory' => 512,
        ],
        'supervisor-ingest' => [
            'connection' => 'redis',
            'queue' => ['ingest'],
            'balance' => 'auto',
            'minProcesses' => (int) env('HORIZON_INGEST_MIN_PROCESSES', 3),
            'maxProcesses' => (int) env('HORIZON_INGEST_MAX_PROCESSES', 10),
            'timeout' => 60,
            'retry_after' => 90,
            'tries' => 2,
            'memory' => 256,
        ],
    ],

    'environments' => [
        'production' => [
            'supervisor-default' => [
                'balanceMaxShift' => 2,
                'balanceCooldown' => 5,
            ],
            'supervisor-low' => [],
            'supervisor-ingest' => [
                'balanceMaxShift' => 3,
                'balanceCooldown' => 3,
            ],
        ],

        'local' => [
            'supervisor-default' => ['maxProcesses' => 2],
            'supervisor-low' => ['maxProcesses' => 1],
            'supervisor-ingest' => ['maxProcesses' => 2],
        ],

        'testing' => [
            'supervisor-default' => ['maxProcesses' => 2],
            'supervisor-low' => ['maxProcesses' => 1],
            'supervisor-ingest' => ['maxProcesses' => 2],
        ],

        'test' => [
            'supervisor-default' => ['maxProcesses' => 2],
            'supervisor-low' => ['maxProcesses' => 1],
            'supervisor-ingest' => ['maxProcesses' => 2],
        ],

        'demo' => [
            'supervisor-default' => ['maxProcesses' => 2],
            'supervisor-low' => ['maxProcesses' => 1],
            'supervisor-ingest' => ['maxProcesses' => 2],
        ],

        'dev' => [
            'supervisor-default' => ['maxProcesses' => 2],
            'supervisor-low' => ['maxProcesses' => 1],
            'supervisor-ingest' => ['maxProcesses' => 2],
        ],
    ],
];
