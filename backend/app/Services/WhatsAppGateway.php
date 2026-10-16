<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Pengiriman pesan WhatsApp melalui gateway Fonnte.
 *
 * Kredensial diambil dari konfigurasi (env), bukan hardcode. Bila token belum
 * diatur, pengiriman dilewati dan dilaporkan sebagai `not_configured` — bukan
 * dianggap sukses.
 */
class WhatsAppGateway
{
    /**
     * @return array{status: string, message: string}
     */
    public function send(?string $phone, string $message): array
    {
        $token = config('services.fonnte.token');

        if (empty($token)) {
            return [
                'status' => 'not_configured',
                'message' => 'Layanan WhatsApp belum dikonfigurasi (FONNTE_TOKEN kosong).',
            ];
        }

        if (empty($phone)) {
            return [
                'status' => 'failed',
                'message' => 'Nomor HP penerima tidak tersedia.',
            ];
        }

        try {
            $response = Http::withHeaders([
                'Authorization' => $token,
            ])->asForm()->post(config('services.fonnte.url'), [
                'target' => $this->normalizePhone($phone),
                'message' => $message,
            ]);

            if ($response->successful()) {
                return [
                    'status' => 'sent',
                    'message' => 'Pesan WhatsApp terkirim.',
                ];
            }

            return [
                'status' => 'failed',
                'message' => 'Gateway WhatsApp menolak permintaan (HTTP '.$response->status().').',
            ];
        } catch (Throwable $e) {
            // Jangan catat isi pesan (memuat tautan aktivasi) ke log.
            report($e);

            return [
                'status' => 'failed',
                'message' => 'Gagal menghubungi gateway WhatsApp.',
            ];
        }
    }

    /**
     * Fonnte menerima format internasional tanpa tanda `+` (mis. 62812...).
     */
    private function normalizePhone(string $phone): string
    {
        $digits = preg_replace('/[^0-9]/', '', $phone) ?? '';

        if (str_starts_with($digits, '0')) {
            return '62'.substr($digits, 1);
        }

        return $digits;
    }
}
