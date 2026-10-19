<?php

namespace App\Support;

use App\Models\Company;
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

        $used = PklPlacement::query()
            ->where('company_id', $company->id)
            ->where('pkl_period_id', $periodId)
            ->where('status', '!=', 'cancelled')
            ->when(
                $ignorePlacementId !== null,
                fn ($q) => $q->where('id', '!=', $ignorePlacementId)
            )
            ->count();

        if ($used >= $quota) {
            return 'Kuota siswa untuk perusahaan pada periode PKL ini sudah penuh.';
        }

        return null;
    }
}
