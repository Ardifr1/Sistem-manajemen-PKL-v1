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

    /*
    |--------------------------------------------------------------------------
    | Password reset request
    |--------------------------------------------------------------------------
    |
    | Permintaan reset password ditindaklanjuti admin. Permintaan yang lebih tua
    | dari batas ini ditandai `expired` dan tidak lagi ditampilkan/diproses;
    | pengguna harus mengajukan permintaan baru.
    |
    */

    'password_reset' => [
        'expires_hours' => (int) env('PASSWORD_RESET_EXPIRES_HOURS', 72),
    ],

    /*
    |--------------------------------------------------------------------------
    | Akun demo
    |--------------------------------------------------------------------------
    |
    | Seeder akun demo memakai password bawaan. Di lingkungan production
    | seeder ini hanya berjalan bila SEED_DEMO_ACCOUNTS=true (mis. server demo
    | sidang yang terkontrol sebelumnya).
    |
    */

    'demo' => [
        'seed_accounts' => filter_var(
            env('SEED_DEMO_ACCOUNTS', false),
            FILTER_VALIDATE_BOOLEAN
        ),
    ],

    /*
    |--------------------------------------------------------------------------
    | AI Journal Assistant
    |--------------------------------------------------------------------------
    |
    | Penyedia model OpenAI-compatible (default Z.ai / GLM). API key HANYA
    | dibaca dari environment server dan tidak pernah dikirim ke frontend.
    | `max_draft_chars` membatasi ukuran input (sekaligus biaya per permintaan).
    |
    */

    'ai' => [
        'api_key' => env('AI_API_KEY'),
        'base_url' => env('AI_BASE_URL', 'https://api.z.ai/api/paas/v4'),
        'model' => env('AI_MODEL', 'glm-5.3'),
        'timeout_seconds' => (int) env('AI_TIMEOUT_SECONDS', 20),
        'max_draft_chars' => (int) env('AI_MAX_DRAFT_CHARS', 1500),
        'rate_limit_per_minute' => (int) env('AI_RATE_LIMIT_PER_MINUTE', 8),
    ],

];
