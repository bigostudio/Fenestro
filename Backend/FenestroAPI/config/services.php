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

    'whatsapp' => [
        // Master switch for every automatic WhatsApp message (lead/partner alerts,
        // statement documents). Off until the Cloud API setup is complete and
        // tested with "php artisan whatsapp:check --to=YOUR_NUMBER".
        'enabled'           => (bool) env('WHATSAPP_SENDING_ENABLED', false),
        'token'             => env('WHATSAPP_TOKEN'),
        'phone_number_id'   => env('WHATSAPP_PHONE_NUMBER_ID'),
        'api_version'       => env('WHATSAPP_API_VERSION', 'v21.0'),
        // WhatsApp Business Account ID (optional; lets the connection check confirm the statement template).
        'business_account_id' => env('WHATSAPP_BUSINESS_ACCOUNT_ID'),
        // Graph API host. Only changed for local testing against a fake server.
        'api_base'          => env('WHATSAPP_API_BASE', 'https://graph.facebook.com'),
        // Webhook (delivery statuses): verify token you choose in the Meta app's
        // Webhooks settings, and the app secret used to check Meta's signature.
        'verify_token'      => env('WHATSAPP_VERIFY_TOKEN'),
        'app_secret'        => env('WHATSAPP_APP_SECRET'),
        'ops_manager_phone' => env('WHATSAPP_OPS_MANAGER_PHONE', '8431474741'),
    ],

];
