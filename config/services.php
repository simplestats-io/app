<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'mailgun' => [
        'domain' => env('MAILGUN_DOMAIN'),
        'secret' => env('MAILGUN_SECRET'),
        'endpoint' => env('MAILGUN_ENDPOINT', 'api.mailgun.net'),
        'scheme' => 'https',
    ],

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'github' => [
        'client_id' => env('GITHUB_CLIENT_ID'),
        'client_secret' => env('GITHUB_CLIENT_SECRET'),
        'redirect' => '/oauth/github/callback',
    ],

    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => '/oauth/google/callback',
    ],

    // Own OAuth client for the Search Console scope, so its Google verification can never
    // affect "Login with Google". Self-hosted setups without social login may reuse GOOGLE_*.
    'google_search_console' => [
        'client_id' => env('GSC_GOOGLE_CLIENT_ID', env('GOOGLE_CLIENT_ID')),
        'client_secret' => env('GSC_GOOGLE_CLIENT_SECRET', env('GOOGLE_CLIENT_SECRET')),
    ],

    'deepl' => [
        'auth_key' => env('DEEPL_AUTH_KEY'),
    ],

];
