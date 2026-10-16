<?php

namespace App\Policies\Concerns;

use App\Models\CompanyMembership;
use App\Models\CompanySupervisor;
use App\Models\User;

trait ResolvesCompanyAccess
{
    /**
     * company_id dari keanggotaan perusahaan yang masih AKTIF
     * (company_memberships.status = active).
     *
     * @return array<int>
     */
    protected function membershipCompanyIds(User $user): array
    {
        return CompanyMembership::query()
            ->where('user_id', $user->id)
            ->where('status', CompanyMembership::STATUS_ACTIVE)
            ->pluck('company_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * company_id yang ditautkan lewat skema lama (company_supervisors).
     * Tautan ini secara implisit adalah keanggotaan aktif juga — dipakai untuk
     * kompatibilitas data lama, bukan untuk menghapus skema tersebut.
     *
     * @return array<int>
     */
    protected function supervisedCompanyIds(User $user): array
    {
        return CompanySupervisor::query()
            ->where('user_id', $user->id)
            ->pluck('company_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * Semua company_id yang menjadi kewenangan user (keanggotaan aktif +
     * tautan pembimbing industri lama).
     *
     * @return array<int>
     */
    protected function authorizedCompanyIds(User $user): array
    {
        return array_values(array_unique(array_merge(
            $this->membershipCompanyIds($user),
            $this->supervisedCompanyIds($user),
        )));
    }

    /**
     * Menentukan apakah user (role company) berwenang terhadap company tertentu.
     */
    protected function isAuthorizedForCompany(User $user, int $companyId): bool
    {
        return in_array((int) $companyId, $this->authorizedCompanyIds($user), true);
    }

    /**
     * Apakah user memiliki keanggotaan perusahaan AKTIF pada $companyId.
     * Keanggotaan nonaktif dianggap tidak ada.
     */
    protected function hasActiveCompanyMembership(User $user, ?int $companyId): bool
    {
        if ($companyId === null) {
            return false;
        }

        return in_array((int) $companyId, $this->authorizedCompanyIds($user), true);
    }

    /**
     * Hard Block konflik kepentingan (PRD bagian C).
     *
     * Bila user memiliki keanggotaan aktif pada Perusahaan X, ia TIDAK boleh
     * melakukan tindakan SISI SEKOLAH (persetujuan/penilaian) yang berkaitan
     * dengan Perusahaan X — termasuk bila ia berrole Admin.
     *
     * Hanya berlaku untuk tindakan sisi sekolah. Tindakan sah Pembimbing
     * Industri (verifikasi jurnal, penilaian industri) tidak terpengaruh.
     */
    protected function hasConflictOfInterest(User $user, ?int $companyId): bool
    {
        return $this->hasActiveCompanyMembership($user, $companyId);
    }

    /**
     * Cek kapabilitas keanggotaan pada sebuah perusahaan.
     *
     * Tautan pembimbing industri lama (company_supervisors) tidak punya baris
     * kapabilitas, sehingga diperlakukan sebagai pemegang seluruh kapabilitas
     * agar perilaku lama tidak berubah.
     */
    protected function hasCompanyCapability(User $user, int $companyId, string $capability): bool
    {
        $membership = CompanyMembership::query()
            ->where('user_id', $user->id)
            ->where('company_id', $companyId)
            ->first();

        if ($membership !== null) {
            return $membership->hasCapability($capability);
        }

        return in_array((int) $companyId, $this->supervisedCompanyIds($user), true);
    }
}
