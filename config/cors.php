<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure your settings for cross-origin resource sharing
    | or "CORS". This determines what cross-origin operations may execute
    | in web browsers. You are free to adjust these settings as needed.
    |
    | To learn more: https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS
    |
    */

    'paths' => [
        'api/*', 
        'sanctum/csrf-cookie',
        'login',
        'register',
        'logout',
        'boards*',
        'user',
        'forgot-password',
        'reset-password',
        'email/verification-notification',
        '/'
    ],


    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],

    'allowed_origins' => [
        'http://localhost:3000',
        'http://192.168.0.12:3000',
        'https://taskflow-frontend-production-9467.up.railway.app',
        // Add your exact Vercel frontend URL here once deployed, e.g.:
        // 'https://taskflow-frontend.vercel.app',
    ],

    // Covers all Vercel preview deployments (branch previews, PR deploys).
    // Restricted to taskflow-* prefix to avoid allowing arbitrary Vercel apps.
    'allowed_origins_patterns' => [
        '#^https://taskflow[a-z0-9\-]*\.vercel\.app$#',
    ],

    'allowed_headers' => ['Content-Type', 'Authorization', 'X-Requested-With', 'X-XSRF-TOKEN', 'Accept'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => true,

];
