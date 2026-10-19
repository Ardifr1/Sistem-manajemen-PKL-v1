<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Email informasi login akun baru: berisi email login dan password sementara.
 *
 * CATATAN KEPUTUSAN: pengiriman password teks biasa via email/WhatsApp adalah
 * permintaan eksplisit pemilik aplikasi (alur password sementara, revisi
 * 2026-10-19). Password tidak pernah ditulis ke log aplikasi.
 */
class AccountCredentialsMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $recipientName,
        public string $loginEmail,
        public string $temporaryPassword,
        public string $roleLabel,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Informasi Login Akun SiMagang',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.account-credentials',
            with: [
                'recipientName' => $this->recipientName,
                'loginEmail' => $this->loginEmail,
                // Password sementara hanya dirender di view, tidak di-log.
                'temporaryPassword' => $this->temporaryPassword,
                'roleLabel' => $this->roleLabel,
            ],
        );
    }
}
