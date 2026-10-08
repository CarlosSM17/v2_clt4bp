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

    'piston' => [
        'url' => env('PISTON_URL', 'http://127.0.0.1:2000/api/v2'),
    ],

    'agente' => [
        'url' => env('AGENTE_URL', 'http://localhost:8100'),
        'token' => env('AGENTE_TOKEN'),
        // Segundos que puede tardar una generación. Con un modelo local, 900; DB_QUEUE_RETRY_AFTER debe superarlo
        'timeout' => (int) env('AGENTE_TIMEOUT', 280),
        // «directo»: Laravel llama al agente en AGENTE_URL. «relevo»: el agente corre en otro equipo (detrás de NAT) y su
        // conector viene por las solicitudes a /api/v1/relevo (ADR 0008); Laravel nunca abre una conexión hacia él
        'modo' => env('AGENTE_MODO', 'directo'),
        // Segundos sin noticias del conector tras los cuales una consulta interactiva falla de inmediato
        'relevo_ausente' => (int) env('AGENTE_RELEVO_AUSENTE', 90),
    ],

];
