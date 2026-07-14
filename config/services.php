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

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'cnb' => [
        'base_url' => env('CNB_API_URL', 'https://api.cnb.cz'),
    ],

    'ares' => [
        'base_url' => env('ARES_API_URL', 'https://ares.gov.cz'),
    ],

    'rpo' => [
        'base_url' => env('RPO_API_URL', 'https://api.statistics.sk'),
    ],

    'vies' => [
        'base_url' => env('VIES_API_URL', 'https://ec.europa.eu/taxation_customs/vies/rest-api'),
    ],

    'crpdph' => [
        'base_url' => env('CRPDPH_API_URL', 'https://adisrws.mfcr.cz'),
    ],

    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_REDIRECT_URI'),
    ],

];
