<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Email informasi akun baru: berisi tautan aktivasi/pengaturan password.
 * Tidak pernah memuat password dalam bentuk teks biasa.
 */
class AccountActivationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $recipientName,
        public string $activationUrl,
        public string $roleLabel,
        public int $expiresHours,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Aktivasi Akun SiMagang',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.account-activation',
            with: [
                'recipientName' => $this->recipientName,
                'activationUrl' => $this->activationUrl,
                'roleLabel' => $this->roleLabel,
                'expiresHours' => $this->expiresHours,
            ],
        );
    }
}
