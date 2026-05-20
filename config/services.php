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
    'twilio' => [
        'sid'           => env('TWILIO_SID'),
        'auth_token'    => env('TWILIO_AUTH_TOKEN'),
        'whatsapp_from' => env('TWILIO_WHATSAPP_FROM'),
        'content_sid'   => env('TWILIO_CONTENT_SID'),
    ],

    'chargily' => [
        'secret_key' => env('CHARGILY_SECRET_KEY'),
        'mode'       => env('CHARGILY_MODE', 'test'), // 'test' or 'live'
    ],

    // ── BReCAI FastAPI microservice (HuggingFace Spaces) ──────────────────────
    'brecai' => [
        'url'      => env('BRECAI_FASTAPI_URL', 'https://ahmedchikhsalah-brecai-api.hf.space'),
        'secret'   => env('BRECAI_INTERNAL_SECRET', 'change-me-in-production'),
        'hf_token' => env('HF_TOKEN'),
    ],

    // ── Modal GPU service (full pipeline: tile + CONCH + A6 fusion) ───────────
    // When set, A6 image+clinical predictions go directly to Modal instead of HF,
    // cutting the round-trip and using the warm GPU container end-to-end.
    'modal' => [
        'url' => env('MODAL_URL', 'https://brest-cancer-detection-project-m2--brecai-extract.modal.run'),
    ],

    // ── Cloudflare R2 object storage ─────────────────────────────────────────
    'r2' => [
        'account_id' => env('R2_ACCOUNT_ID'),
        'access_key' => env('R2_ACCESS_KEY_ID'),
        'secret_key' => env('R2_SECRET_ACCESS_KEY'),
        'bucket'     => env('R2_BUCKET', 'slidesbucket'),
        'endpoint'   => env('R2_ENDPOINT'),
        'public_url' => env('R2_PUBLIC_URL'), // optional CDN URL
    ],

];

