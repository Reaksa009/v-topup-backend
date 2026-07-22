<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Configure CORS settings to safely allow production requests from
    | Vercel frontend deployments (Customer storefront & Admin panel).
    |
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie', 'storage/*'],

    'allowed_methods' => ['*'],

    'allowed_origins' => [
        env('FRONTEND_URL', 'https://v-topup-frontend.vercel.app'),
        env('ADMIN_FRONTEND_URL', 'https://t-topup-admin.vercel.app'),
        'http://localhost:5173',
        'http://localhost:5174',
        'http://localhost:3000',
    ],

    'allowed_origins_patterns' => [
        '#^https://.*\-reaksas-projects-b17183b0\.vercel\.app$#',
        '#^https://v-topup-frontend.*\.vercel\.app$#',
        '#^https://t-topup-admin.*\.vercel\.app$#',
    ],

    'allowed_headers' => ['*'],

    'exposed_headers' => ['X-Request-ID'],

    'max_age' => 86400,

    'supports_credentials' => true,

];
