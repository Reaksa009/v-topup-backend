<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | Store configuration credentials for third-party API services (G2Bulk,
    | KHQR, Telegram, etc.) avoiding hardcoded tokens in services or controllers.
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

    'telegram' => [
        'bot_token' => env('TELEGRAM_BOT_TOKEN'),
        'admin_chat_id' => env('TELEGRAM_ADMIN_CHAT_ID'),
    ],

    'g2bulk' => [
        'api_key' => env('G2BULK_API_KEY', '5fdcdd6b1a6d04645af01f89d21cd68a55b839ae8b36308f1ccab8f6cf982bfe'),
        'base_url' => env('G2BULK_BASE_URL', 'https://api.g2bulk.com/v1'),
    ],

    'khqr' => [
        'base_url' => env('KHQR_API_BASE_URL', 'https://api.khqr.link'),
        'token' => env('KHQR_API_TOKEN'),
        'bakong_account_id' => env('KHQR_BAKONG_ACCOUNT_ID'),
        'merchant_name' => env('KHQR_ACCOUNT_NAME', 'V-TOPUP-STORE CO., LTD.'),
    ],

];
