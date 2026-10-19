<?php

namespace App\Policies\Concerns;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Hard Block konflik kepentingan (aturan default, tanpa pengecualian).
 *
 * Jika pengguna memiliki keanggotaan perusahaan yang AKTIF pada
 * Perusahaan X, ia tidak boleh melakukan persetujuan, verifikasi, atau
 * keputusan dalam kapasitas SEKOLAH terhadap Perusahaan X.
 *
 * - Berlaku untuk semua role termasuk admin (tanpa pengecualian).
 * - Hanya memblokir tindakan kapasitas sekolah; fungsi perusahaan
 *   (mis. verifikasi jurnal oleh pembimbing industri) tidak diblokir.
 * - Setiap penolakan dicatat di audit log agar dapat ditangani petugas
 *   sekolah lain yang berwenang.
 */
trait BlocksCompanyConflict
{
    use ResolvesCompanyAccess;

    /**
     * Tolak (403 + audit log) bila pengguna memiliki konflik kepentingan
     * aktif terhadap perusahaan yang menjadi subjek tindakan sekolah.
     */
    protected function denyOnCompanyConflict(
        User $user,
        int $companyId,
        string $action,
        ?Model $subject = null,
        ?string $detail = null,
    ): void {
        if (! $this->hasConflictOfInterest($user, $companyId)) {
            return;
        }

        AuditLog::create([
            'user_id' => $user->id,
            'action' => 'hard_block.'.$action,
            'subject_type' => $subject ? $subject::class : null,
            'subject_id' => $subject?->getKey(),
            'reason' => 'Ditolak karena konflik kepentingan: pengguna terhubung dengan perusahaan id '
                .$companyId.($detail ? ' — '.$detail : ''),
            'metadata' => ['company_id' => $companyId],
        ]);

        abort(403, 'Konflik kepentingan: Anda terhubung dengan perusahaan ini sehingga tidak dapat mengambil keputusan dalam kapasitas sekolah.');
    }
}
