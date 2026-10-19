<?php

namespace App\Support;

/**
 * Generator password sementara untuk akun baru.
 *
 * - Acak kriptografis (random_bytes) sehingga unik per pengguna.
 * - Kuat: 12 karakter dengan huruf kecil, huruf besar, angka, simbol.
 * - Mudah dibaca di WhatsApp: karakter ambigu (0/O, 1/l/I) dibuang.
 */
class TemporaryPassword
{
    private const LOWER = 'abcdefghjkmnpqrstuvwxyz';

    private const UPPER = 'ABCDEFGHJKMNPQRSTUVWXYZ';

    private const DIGITS = '23456789';

    private const SYMBOLS = '!@#$%&*?';

    /**
     * Hasilkan password sementara yang acak dan kuat.
     */
    public static function generate(int $length = 12): string
    {
        $length = max($length, 8);

        $all = self::LOWER.self::UPPER.self::DIGITS.self::SYMBOLS;

        // Jamin tiap kelas karakter muncul minimal satu kali.
        $chars = [
            self::pick(self::LOWER),
            self::pick(self::UPPER),
            self::pick(self::DIGITS),
            self::pick(self::SYMBOLS),
        ];

        for ($i = count($chars); $i < $length; $i++) {
            $chars[] = self::pick($all);
        }

        // Acak urutan agar posisi kelas tidak tertebak.
        for ($i = count($chars) - 1; $i > 0; $i--) {
            $j = random_int(0, $i);
            [$chars[$i], $chars[$j]] = [$chars[$j], $chars[$i]];
        }

        return implode('', $chars);
    }

    private static function pick(string $pool): string
    {
        return $pool[random_int(0, strlen($pool) - 1)];
    }
}
