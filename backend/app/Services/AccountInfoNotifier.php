<?php

namespace App\Services;

use App\Mail\AccountActivationMail;
use App\Mail\AccountCredentialsMail;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Mengirim informasi akun baru ke email dan WhatsApp.
 *
 * Dua mode pengiriman:
 * - send(): TAUTAN AKTIVASI (mode lama, dipertahankan untuk kompatibilitas
 *   akun yang masih memegang token aktivasi).
 * - sendCredentials(): EMAIL LOGIN + PASSWORD SEMENTARA (mode aktif sejak
 *   revisi 2026-10-19; pesan WhatsApp tidak lagi memuat tautan/token).
 *
 * Mengembalikan status tiap kanal agar admin dapat melihat bila pengiriman
 * gagal. Password tidak pernah ditulis ke log.
 */
class AccountInfoNotifier
{
    /** Label peran untuk pesan. */
    private const ROLE_LABEL = [
        'admin' => 'Admin Sekolah',
        'teacher' => 'Guru Pembimbing',
        'student' => 'Siswa',
        'company' => 'Perusahaan',
        'supervisor' => 'Pembimbing Industri',
    ];

    public function __construct(private WhatsAppGateway $whatsapp)
    {
    }

    /**
     * @return array{email: array{status:string,message:string,driver?:string}, whatsapp: array{status:string,message:string}}
     */
    public function send(User $user, string $rawToken): array
    {
        $url = $this->activationUrl($user, $rawToken);
        $expiresHours = (int) config('simagang.activation.expires_hours', 72);
        $roleLabel = self::ROLE_LABEL[$user->role] ?? $user->role;

        return [
            'email' => $this->sendEmail($user, $url, $roleLabel, $expiresHours),
            'whatsapp' => $this->whatsapp->send($user->phone, $this->whatsAppMessage($user, $url, $roleLabel, $expiresHours)),
        ];
    }

    public function activationUrl(User $user, string $rawToken): string
    {
        $base = rtrim((string) config('simagang.frontend_url'), '/');
        $path = '/'.ltrim((string) config('simagang.activation.path', '/aktivasi'), '/');

        return $base.$path.'?'.http_build_query([
            'email' => $user->email,
            'token' => $rawToken,
        ]);
    }

    /**
     * Kirim email login + password sementara via email dan WhatsApp.
     *
     * @return array{email: array{status:string,message:string,driver?:string}, whatsapp: array{status:string,message:string}}
     */
    public function sendCredentials(User $user, string $temporaryPassword): array
    {
        $roleLabel = self::ROLE_LABEL[$user->role] ?? $user->role;

        return [
            'email' => $this->sendCredentialsEmail($user, $temporaryPassword, $roleLabel),
            'whatsapp' => $this->whatsapp->send(
                $user->phone,
                $this->credentialsWhatsAppMessage($user, $temporaryPassword)
            ),
        ];
    }

    private function credentialsWhatsAppMessage(User $user, string $temporaryPassword): string
    {
        return "Halo {$user->name}, akun SiMagang Anda berhasil dibuat.\n\n"
            ."Berikut informasi login Anda:\n\n"
            ."Email login: {$user->email}\n"
            ."Password sementara: {$temporaryPassword}\n\n"
            ."Silakan login ke SiMagang menggunakan informasi tersebut. "
            ."Demi keamanan, Anda wajib mengganti password saat pertama kali masuk.";
    }

    /**
     * @return array{status:string,message:string,driver?:string}
     */
    private function sendCredentialsEmail(User $user, string $temporaryPassword, string $roleLabel): array
    {
        $driver = (string) config('mail.default');

        // Mailer `log`/`array` menulis seluruh isi email ke log — password
        // sementara tidak boleh bocor ke log. Laporkan apa adanya.
        if (in_array($driver, ['log', 'array'], true)) {
            return [
                'status' => 'not_configured',
                'message' => "Email tidak dikirim (mailer \"{$driver}\" menulis isi email ke log). Atur MAIL_MAILER SMTP/Gmail.",
                'driver' => $driver,
            ];
        }

        try {
            Mail::to($user->email)->send(
                new AccountCredentialsMail($user->name, $user->email, $temporaryPassword, $roleLabel)
            );
        } catch (Throwable $e) {
            report($e);

            return [
                'status' => 'failed',
                'message' => 'Email gagal dikirim.',
                'driver' => $driver,
            ];
        }

        return [
            'status' => 'sent',
            'message' => 'Email informasi login terkirim.',
            'driver' => $driver,
        ];
    }

    private function whatsAppMessage(User $user, string $url, string $roleLabel, int $expiresHours): string
    {
        return "Halo {$user->name}, akun SiMagang Anda ({$roleLabel}) telah dibuat.\n"
            ."Atur password Anda melalui tautan berikut (berlaku {$expiresHours} jam):\n"
            .$url."\n\n"
            .'Demi keamanan, password tidak pernah dikirim melalui WhatsApp.';
    }

    /**
     * @return array{status:string,message:string,driver?:string}
     */
    private function sendEmail(User $user, string $url, string $roleLabel, int $expiresHours): array
    {
        $driver = (string) config('mail.default');

        // Mailer `log` menulis seluruh isi email ke laravel.log, termasuk
        // tautan yang memuat token aktivasi. Jangan gunakan kanal ini untuk
        // email aktivasi agar token tidak bocor ke log.
        if ($driver === 'log') {
            return [
                'status' => 'not_configured',
                'message' => 'Email tidak dikirim karena MAIL_MAILER=log (token aktivasi tidak boleh ditulis ke log). Atur MAIL_MAILER SMTP/Gmail.',
                'driver' => $driver,
            ];
        }

        try {
            Mail::to($user->email)->send(
                new AccountActivationMail($user->name, $url, $roleLabel, $expiresHours)
            );
        } catch (Throwable $e) {
            report($e);

            return [
                'status' => 'failed',
                'message' => 'Email gagal dikirim.',
                'driver' => $driver,
            ];
        }

        // Mailer `log`/`array` tidak benar-benar mengirim — laporkan apa adanya.
        if (in_array($driver, ['log', 'array'], true)) {
            return [
                'status' => 'not_configured',
                'message' => "Email belum terkirim ke penerima (mailer \"{$driver}\"). Atur MAIL_MAILER SMTP/Gmail.",
                'driver' => $driver,
            ];
        }

        return [
            'status' => 'sent',
            'message' => 'Email aktivasi terkirim.',
            'driver' => $driver,
        ];
    }
}
