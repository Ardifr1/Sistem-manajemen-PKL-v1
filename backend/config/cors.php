<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Atur origin yang diizinkan via env CORS_ALLOWED_ORIGINS (pisahkan koma).
    | Hanya localhost/127.0.0.1 yang diizinkan secara default untuk pengembangan.
    | Origin lain (termasuk tunnel sementara) HARUS didaftarkan eksplisit —
    | wildcard domain publik tidak dipakai karena siapa pun dapat membuat
    | tunnel di domain tersebut dan mengirim permintaan ber-kredensial.
    |
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => array_filter(array_map('trim', explode(',', env('CORS_ALLOWED_ORIGINS', '')))),

    'allowed_origins_patterns' => [
        '#^http://localhost(:\d+)?$#',
        '#^http://127\.0\.0\.1(:\d+)?$#',
    ],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => true,

];
