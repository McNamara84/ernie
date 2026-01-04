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

    'elmo' => [
        'api_key' => env('ELMO_API_KEY'),
    ],

    'google_maps' => [
        'api_key' => env('GM_API_KEY', ''),
    ],

    'orcid' => [
        'api_url' => env('ORCID_API_URL', 'https://pub.orcid.org/v3.0'),
        'search_url' => env('ORCID_SEARCH_URL', 'https://pub.orcid.org/v3.0/search'),
    ],

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Slug Generator Service
    |--------------------------------------------------------------------------
    |
    | Configuration for the SlugGeneratorService that creates URL-friendly
    | slugs from resource titles.
    |
    | disable_iconv: Set to true in multi-threaded PHP environments (Swoole,
    |                ReactPHP, parallel extensions) where setlocale() is not
    |                thread-safe. When disabled, only the built-in character
    |                map is used for transliteration.
    |
    */
    'slug_generator' => [
        'disable_iconv' => env('SLUG_GENERATOR_DISABLE_ICONV', false),
    ],

];
