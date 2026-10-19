<?php

namespace App\Services;

use App\Support\PhoneNumber;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Pengiriman pesan WhatsApp melalui gateway Fonnte.
 *
 * Kredensial diambil dari konfigurasi (env), bukan hardcode. Bila token belum
 * diatur, pengiriman dilewati dan dilaporkan sebagai `not_configured` — bukan
 * dianggap sukses.
 *
 * Fonnte mengembalikan HTTP 200 bahkan saat gagal (mis. token salah), dengan
 * body `{"status": false, "reason": "..."}`. Karena itu status HTTP saja tidak
 * cukup: isi respons wajib diperiksa.
 */
class WhatsAppGateway
{
    /**
     * @return array{status: string, message: string}
     */
    public function send(?string $phone, string $message): array
    {
        $token = trim((string) config('services.fonnte.token', ''));
        $url = trim((string) config('services.fonnte.url', ''));

        if ($url === '') {
            $url = 'https://api.fonnte.com/send';
        }

        if ($token === '') {
            return [
                'status' => 'not_configured',
                'message' => 'Layanan WhatsApp belum dikonfigurasi (FONNTE_TOKEN kosong).',
            ];
        }

        $target = PhoneNumber::normalize($phone);

        if ($target === null) {
            return [
                'status' => 'failed',
                'message' => 'Nomor HP penerima tidak tersedia.',
            ];
        }

        if (! PhoneNumber::isValid($target)) {
            return [
                'status' => 'failed',
                'message' => 'Nomor HP penerima tidak valid untuk WhatsApp.',
            ];
        }

        try {
            $response = Http::withHeaders([
                // Fonnte memakai token apa adanya, tanpa awalan "Bearer".
                'Authorization' => $token,
                'Accept' => 'application/json',
            ])->asForm()->timeout(15)->post($url, [
                'target' => $target,
                'message' => $message,
                'countryCode' => '62',
            ]);
        } catch (Throwable $e) {
            // Jangan catat pesan/URL aktivasi maupun header Authorization.
            Log::warning('Gagal menghubungi gateway WhatsApp Fonnte.', [
                'target' => PhoneNumber::mask($target),
                'exception' => $e::class,
            ]);

            return [
                'status' => 'failed',
                'message' => 'Gagal menghubungi gateway WhatsApp.',
            ];
        }

        $payload = $response->json();
        $payload = is_array($payload) ? $payload : [];
        $providerStatus = $payload['status'] ?? $payload['Status'] ?? null;
        $reason = $this->safeReason($payload['reason'] ?? null);

        if ($response->successful() && $this->isProviderSuccess($providerStatus)) {
            return [
                'status' => 'sent',
                'message' => 'Pesan WhatsApp terkirim.',
            ];
        }

        Log::warning('Fonnte menolak pengiriman WhatsApp.', [
            'http_status' => $response->status(),
            'target' => PhoneNumber::mask($target),
            'provider_status' => is_scalar($providerStatus) ? (string) $providerStatus : null,
            'reason' => $reason,
        ]);

        if (! $response->successful()) {
            return [
                'status' => 'failed',
                'message' => 'Gateway WhatsApp menolak permintaan (HTTP '.$response->status().').'
                    .($reason !== null ? ' Alasan: '.$reason : ''),
            ];
        }

        return [
            'status' => 'failed',
            'message' => 'Fonnte tidak mengonfirmasi pengiriman.'
                .($reason !== null ? ' Alasan: '.$reason : ' Periksa token dan status perangkat.'),
        ];
    }

    /**
     * Fonnte memakai `status: true` (kadang `Status`) saat berhasil. Nilai
     * string "true"/"1" juga diterima karena sebagian respons gateway berupa
     * form/JSON longgar.
     */
    private function isProviderSuccess(mixed $status): bool
    {
        if ($status === true || $status === 1) {
            return true;
        }

        return is_string($status) && in_array(strtolower($status), ['true', '1', 'success'], true);
    }

    /**
     * Bersihkan alasan dari Fonnte sebelum ditampilkan/dilog: batasi panjang
     * dan buang token, tautan aktivasi, serta string 64-hex agar token tidak
     * pernah masuk log.
     */
    private function safeReason(mixed $reason): ?string
    {
        if (! is_string($reason) || trim($reason) === '') {
            return null;
        }

        $reason = trim($reason);
        $token = trim((string) config('services.fonnte.token', ''));

        if ($token !== '') {
            $reason = str_replace($token, '[token disembunyikan]', $reason);
        }

        $reason = preg_replace('~https?://\S+~i', '[tautan disembunyikan]', $reason) ?? $reason;
        $reason = preg_replace('/\b[a-f0-9]{64}\b/i', '[token disembunyikan]', $reason) ?? $reason;

        return mb_strimwidth($reason, 0, 200, '…');
    }
}
