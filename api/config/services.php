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

    // Microsoft 365 / Graph mail (supplier enquiry sending + reply tracking). See docs/MAIL_INTEGRATION.md
    'microsoft' => [
        'transport' => env('MAIL_TRANSPORT'), // graph | log ; empty = graph when MS_* are set, else log
        'tenant_id' => env('MS_TENANT_ID'),
        'client_id' => env('MS_CLIENT_ID'),
        'client_secret' => env('MS_CLIENT_SECRET'),
        'mailbox' => env('MS_MAILBOX', 'enquiry@indianrounds.in'),
        'inbox_folder' => env('MS_INBOX_FOLDER', 'Inbox'),
        'processed_category' => env('MS_MAIL_CATEGORY_PROCESSED', 'ERP-Processed'),
        'graph_base' => env('MS_GRAPH_BASE', 'https://graph.microsoft.com/v1.0'),
        'login_base' => env('MS_LOGIN_BASE', 'https://login.microsoftonline.com'),
        'timeout' => (int) env('MS_HTTP_TIMEOUT', 25),
        'max_retries' => (int) env('MS_HTTP_RETRIES', 3),
        'retry_max_wait' => (int) env('MS_HTTP_RETRY_MAX_WAIT', 30), // seconds, cap for Retry-After / backoff
        'poll_overlap_minutes' => (int) env('MS_POLL_OVERLAP_MINUTES', 10),
        'poll_lookback_hours' => (int) env('MS_POLL_LOOKBACK_HOURS', 48), // first poll only
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

];
