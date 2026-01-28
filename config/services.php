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

    'ernie' => [
        'api_key' => env('ERNIE_API_KEY'),
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
    | enable_iconv: iconv transliteration is DISABLED by default for thread safety.
    |               setlocale() used by iconv is not thread-safe and can cause
    |               subtle bugs in multi-threaded PHP environments (Swoole,
    |               ReactPHP, parallel extensions, PHP-FPM with threads).
    |
    |               Only enable if you:
    |               1. Need transliteration of unusual characters not in TRANSLITERATION_MAP
    |               2. Are certain your PHP deployment is single-threaded
    |
    |               The built-in TRANSLITERATION_MAP handles common characters
    |               (German umlauts, French accents, Spanish ñ, etc.) without iconv.
    |
    */
    'slug_generator' => [
        'enable_iconv' => env('SLUG_GENERATOR_ENABLE_ICONV', false),
    ],

];
