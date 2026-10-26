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

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'fns' => [
        'base_url' => env('FNS_BASE_URL', 'https://irkkt-mobile.nalog.ru:8888'),
        'client_secret' => env('FNS_CLIENT_SECRET', 'IyvrAbKt9h/8p6a7QPh8gpkXYQ4='),
        'device_id' => env('FNS_DEVICE_ID', '7C82010F-16CC-446B-8F66-FC4080C66521'),
    ],

    'openrouter' => [
        'key' => env('OPENROUTER_API_KEY'),
        'model' => env('OPENROUTER_MODEL', 'qwen/qwen3.8-27b:free'),
        'base_url' => env('OPENROUTER_BASE_URL', 'https://openrouter.ai/api/v1/chat/completions'),
    ],

    'ai' => [
        'provider' => env('AI_PROVIDER', 'openrouter'),
        'key' => env('AI_API_KEY', env('OPENROUTER_API_KEY')),
        'model' => env('AI_MODEL', env('OPENROUTER_MODEL', 'qwen/qwen3.8-27b:free')),
        'base_url' => env('AI_BASE_URL', env('OPENROUTER_BASE_URL', 'https://openrouter.ai/api/v1/chat/completions')),
        'system_prompt' => env('AI_SYSTEM_PROMPT'),
    ],

];
