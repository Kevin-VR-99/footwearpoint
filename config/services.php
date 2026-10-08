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

    /*
    | TG-225 (G6) — Aplicación de FootwearPoint en Mercado Pago Developers,
    | para que cada distribuidora conecte SU cuenta con el flujo oficial
    | (OAuth). Las credenciales solo viven en variables de entorno (Railway o
    | el .env local), nunca en el código.
    |
    | redirect_uri debe ser EXACTAMENTE la URL registrada en la aplicación de
    | Mercado Pago (por ejemplo https://<dominio>/mercado-pago/callback).
    | modo: 'sandbox' (pruebas, pide credenciales de prueba) o 'produccion'.
    */
    'mercadopago' => [
        'client_id'        => env('MP_CLIENT_ID'),
        'client_secret'    => env('MP_CLIENT_SECRET'),
        'redirect_uri'     => env('MP_REDIRECT_URI'),
        'modo'             => env('MP_MODO', 'sandbox'),
        'pkce'             => (bool) env('MP_PKCE', true),
        'url_autorizacion' => 'https://auth.mercadopago.com/authorization',
        'url_api'          => 'https://api.mercadopago.com',
        'timeout'          => 10,
    ],

];
