<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Aktivasi Akun SiMagang</title>
</head>
<body style="font-family: Arial, Helvetica, sans-serif; color:#0f172a; line-height:1.6;">
    <h2 style="margin:0 0 12px;">Halo {{ $recipientName }},</h2>

    <p>
        Akun SiMagang Anda (peran: <strong>{{ $roleLabel }}</strong>) telah dibuat oleh admin sekolah.
        Untuk mengaktifkan akun dan mengatur password, klik tautan di bawah ini:
    </p>

    <p style="margin:20px 0;">
        <a href="{{ $activationUrl }}"
           style="display:inline-block;padding:12px 20px;background:#2563eb;color:#fff;border-radius:8px;text-decoration:none;">
            Atur Password Saya
        </a>
    </p>

    <p style="font-size:13px;color:#64748b;">
        Tautan berlaku {{ $expiresHours }} jam sejak email ini dikirim. Bila tautan kadaluarsa,
        minta admin mengirim ulang informasi akun.
    </p>

    <p style="font-size:13px;color:#64748b;">
        Demi keamanan, kami tidak pernah mengirim password melalui email atau WhatsApp.
        Abaikan email ini bila Anda merasa tidak pernah mendaftar.
    </p>
</body>
</html>
