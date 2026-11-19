<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
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

    // Hospify's SMS provider. Only used to confirm a patient's phone at registration.
    'termii' => [
        'api_key' => env('TERMII_API_KEY'),
        'sender_id' => env('TERMII_SENDER_ID'),
        // Termii gives each account its own address (dashboard → API settings).
        'base_url' => env('TERMII_BASE_URL', 'https://v4.api.termii.com'),
        // "dnd" reaches numbers on Do-Not-Disturb; it needs activating with Termii.
        'channel' => env('TERMII_CHANNEL', 'generic'),
    ],

    // Hospify's LiveKit server for calls. The secret signs join tokens; the apps never see it.
    'hospify' => [
        // Hospify signs sign-in passes and service calls with its own ES256 key;
        // NHC keeps only the public half.
        'public_key' => env('HOSPIFY_PUBLIC_KEY'),
        // Optional Hospify Connect: leave these blank and the install runs on its
        // own. Set them only if Hospify has connected your install to the Citizen app.
        'api_url' => env('HOSPIFY_API_URL', 'https://api.hospify.cloud'),
        'events_secret' => env('NHC_EVENTS_SECRET'),
        'patient_token_minutes' => (int) env('NHC_PATIENT_TOKEN_MINUTES', 60),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
