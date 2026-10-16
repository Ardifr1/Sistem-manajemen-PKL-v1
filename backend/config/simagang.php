<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Frontend URL
    |--------------------------------------------------------------------------
    |
    | Basis URL aplikasi SPA (React). Dipakai untuk membangun tautan aktivasi
    | akun yang dikirim ke email/WhatsApp pengguna baru.
    |
    */

    'frontend_url' => env('FRONTEND_URL', 'http://localhost:5173'),

    /*
    |--------------------------------------------------------------------------
    | Account activation
    |--------------------------------------------------------------------------
    |
    | Path halaman SPA untuk mengatur password awal, dan masa berlaku tautan
    | aktivasi (jam). Token disimpan sebagai hash; tautan memuat token mentah.
    |
    */

    'activation' => [
        'path' => env('ACCOUNT_ACTIVATION_PATH', '/aktivasi'),
        'expires_hours' => (int) env('ACCOUNT_ACTIVATION_EXPIRES_HOURS', 72),
    ],

];
