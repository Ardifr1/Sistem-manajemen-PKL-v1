<?php

namespace App\Policies;

use App\Models\User;

class CompanyPolicy
{
    /**
     * Daftar perusahaan boleh dibaca semua pengguna terautentikasi
     * (dipakai halaman pemilihan perusahaan oleh siswa).
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user): bool
    {
        return true;
    }

    /**
     * Data master perusahaan (mitra) hanya dikelola Admin.
     */
    public function create(User $user): bool
    {
        return $user->role === 'admin';
    }

    public function update(User $user): bool
    {
        return $user->role === 'admin';
    }

    public function delete(User $user): bool
    {
        return $user->role === 'admin';
    }

    /**
     * Pengajuan perusahaan NONMITRA hanya dapat dilakukan siswa.
     * Hasilnya tetap harus diverifikasi Admin Sekolah sebelum dapat dipakai.
     */
    public function submitProposal(User $user): bool
    {
        return $user->role === 'student';
    }

    /**
     * Verifikasi pengajuan perusahaan hanya oleh Admin Sekolah.
     */
    public function verifyProposal(User $user): bool
    {
        return $user->role === 'admin';
    }
}
