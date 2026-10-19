<?php

namespace App\Support;

/**
 * Normalisasi nomor HP/WhatsApp Indonesia ke format internasional.
 *
 * Fonnte menerima nomor tanpa tanda `+` (mis. 628123456789). Menyimpan nomor
 * dalam format ini membuat pengiriman konsisten, terlepas dari apakah admin
 * mengetik `08…`, `+62…`, atau `62…`.
 */
class PhoneNumber
{
    /** Format baku setelah normalisasi: 62 + 8 + 7..12 digit. */
    public const REGEX = '/^628[0-9]{7,12}$/';

    /**
     * Ubah nomor ke format `628xxxxxxxxxx`.
     *
     * Pemisah umum (spasi, strip, titik, kurung) dibuang agar nomor yang
     * diketik manusia tetap diterima. Karakter lain dibiarkan apa adanya
     * supaya validator menolaknya, bukan diam-diam "diperbaiki".
     */
    public static function normalize(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);

        if ($value === '') {
            return null;
        }

        $value = preg_replace('/[\s\-().]+/', '', $value) ?? $value;

        // Hanya angka (opsional diawali +) yang boleh dilanjutkan.
        if (! preg_match('/^\+?[0-9]+$/', $value)) {
            return $value;
        }

        $digits = ltrim($value, '+');

        if (str_starts_with($digits, '0')) {
            return '62'.substr($digits, 1);
        }

        return $digits;
    }

    /** Apakah nomor (dalam format apa pun yang didukung) valid? */
    public static function isValid(?string $value): bool
    {
        $normalized = self::normalize($value);

        return $normalized !== null
            && preg_match(self::REGEX, $normalized) === 1;
    }

    /**
     * Samarkan nomor untuk log (mis. 6281****90) agar data pribadi tidak
     * tercatat lengkap.
     */
    public static function mask(?string $value): string
    {
        $normalized = self::normalize($value);

        if ($normalized === null || strlen($normalized) < 6) {
            return '(tidak tersedia)';
        }

        return substr($normalized, 0, 4).'****'.substr($normalized, -2);
    }
}
