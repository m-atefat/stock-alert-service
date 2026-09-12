<?php

return [

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

    'goldapi' => [
        'key' => env('GOLDAPI_IO_API_KEY'),
        'base_url' => env('GOLDAPI_IO_BASE_URL', 'https://www.goldapi.io/api'),
        'timeout' => (int) env('GOLDAPI_IO_TIMEOUT', 5),

        'proxy' => [
            'enabled' => (bool) env('GOLDAPI_IO_PROXY_ENABLED', false),
            'url' => env('GOLDAPI_IO_PROXY_URL', 'socks5h://127.0.0.1:10808'),
        ],
    ],

];
