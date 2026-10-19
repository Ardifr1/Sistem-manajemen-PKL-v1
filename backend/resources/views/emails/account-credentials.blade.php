<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Informasi Login Akun SiMagang</title>
</head>
<body style="font-family: Arial, sans-serif; color: #0f172a; line-height: 1.6;">
    <h2>Halo, {{ $recipientName }}!</h2>
    <p>Akun SiMagang Anda berhasil dibuat dengan peran <strong>{{ $roleLabel }}</strong>.</p>
    <p>Berikut informasi login Anda:</p>
    <table style="border-collapse: collapse;">
        <tr>
            <td style="padding: 6px 12px 6px 0; color: #64748b;">Email login</td>
            <td style="padding: 6px 0;"><strong>{{ $loginEmail }}</strong></td>
        </tr>
        <tr>
            <td style="padding: 6px 12px 6px 0; color: #64748b;">Password sementara</td>
            <td style="padding: 6px 0;"><strong>{{ $temporaryPassword }}</strong></td>
        </tr>
    </table>
    <p>Silakan login ke SiMagang menggunakan informasi tersebut. Demi keamanan, Anda <strong>wajib mengganti password</strong> saat pertama kali masuk.</p>
    <p style="color: #64748b; font-size: 13px;">Jika Anda tidak merasa didaftarkan, hubungi admin sekolah.</p>
</body>
</html>
