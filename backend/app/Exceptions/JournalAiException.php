<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Kegagalan layanan AI Journal Assistant yang sudah dipetakan ke respons API.
 *
 * `reason` dipakai controller untuk menentukan status HTTP, tanpa membocorkan
 * detail internal (API key, body provider) ke klien.
 */
class JournalAiException extends RuntimeException
{
    public const NOT_CONFIGURED = 'not_configured';

    public const UNAVAILABLE = 'unavailable';

    public const INVALID_RESPONSE = 'invalid_response';

    public function __construct(
        public readonly string $reason,
        string $message = ''
    ) {
        parent::__construct($message);
    }

    public static function notConfigured(): self
    {
        return new self(
            self::NOT_CONFIGURED,
            'Fitur AI belum dikonfigurasi pada server. Hubungi admin sekolah.'
        );
    }

    public static function unavailable(): self
    {
        return new self(
            self::UNAVAILABLE,
            'Layanan AI sedang tidak dapat dihubungi. Coba lagi beberapa saat lagi.'
        );
    }

    public static function quotaExceeded(): self
    {
        return new self(
            self::UNAVAILABLE,
            'Kuota layanan AI sedang habis. Coba lagi nanti atau lanjutkan jurnal secara manual.'
        );
    }

    public static function invalidResponse(): self
    {
        return new self(
            self::INVALID_RESPONSE,
            'Respons AI tidak dapat diproses. Coba lagi atau lanjutkan jurnal secara manual.'
        );
    }
}
