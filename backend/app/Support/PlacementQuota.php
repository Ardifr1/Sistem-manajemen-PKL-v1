<?php

namespace App\Support;

use App\Models\Company;
use App\Models\PklApplication;
use App\Models\PklPlacement;

/**
 * Pemeriksaan kuota perusahaan untuk penempatan PKL.
 *
 * Dipakai baik oleh pembuatan penempatan manual (admin) maupun otomatis
 * (siswa memilih tawaran) agar aturannya tunggal.
 */
class PlacementQuota
{
    /**
     * Kembalikan pesan error bila kuota tidak mencukupi, atau null bila aman.
     */
    public static function errorMessage(
        Company $company,
        int $periodId,
        ?int $ignorePlacementId = null
    ): ?string {
        $quota = (int) $company->student_quota;

        if ($quota <= 0) {
            return 'Perusahaan tidak memiliki kuota siswa.';
        }

        $used = self::usedStudentCount($company, $periodId, $ignorePlacementId);

        if ($used >= $quota) {
            return 'Kuota siswa untuk perusahaan pada periode PKL ini sudah penuh.';
        }

        return null;
    }

    /**
     * Hitung jumlah siswa unik yang sudah berkontribusi pada kuota perusahaan
     * untuk periode tertentu. Siswa yang memiliki penempatan aktif (non-cancelled)
     * atau aplikasi accepted/approved dikira satu slot yang sama — tanpa
     * menghitung siswa yang sama dua kali.
     */
    public static function usedStudentCount(
        Company $company,
        int $periodId,
        ?int $ignorePlacementId = null
    ): int {
        $placementStudentIds = PklPlacement::query()
            ->where('company_id', $company->id)
            ->where('pkl_period_id', $periodId)
            ->where('status', '!=', 'cancelled')
            ->when(
                $ignorePlacementId !== null,
                fn ($q) => $q->where('id', '!=', $ignorePlacementId)
            )
            ->pluck('student_id')
            ->unique()
            ->all();

        $applicationStudentIds = PklApplication::query()
            ->where('company_id', $company->id)
            ->where('pkl_period_id', $periodId)
            ->whereIn('status', ['accepted', 'approved'])
            ->pluck('student_id')
            ->unique()
            ->all();

        return count(array_unique([...$placementStudentIds, ...$applicationStudentIds]));
    }
}
