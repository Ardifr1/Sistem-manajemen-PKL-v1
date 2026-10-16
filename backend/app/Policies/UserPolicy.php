<?php

namespace App\Policies;

use App\Models\User;

class UserPolicy
{
    /**
     * Daftar pengguna boleh dibaca role yang berhak, namun query-nya selalu
     * dibatasi di controller (guru -> siswanya, perusahaan -> perusahaannya).
     * Siswa tidak boleh mengakses daftar pengguna sama sekali.
     */
    public function viewAny(User $user): bool
    {
        return in_array($user->role, ['admin', 'teacher', 'company', 'supervisor'], true);
    }

    /**
     * Detail pengguna: admin atau dirinya sendiri.
     */
    public function view(User $user, User $target): bool
    {
        return $user->role === 'admin' || (int) $user->id === (int) $target->id;
    }

    /**
     * Pembuatan akun hanya oleh admin (pengelolaan pengguna).
     */
    public function create(User $user): bool
    {
        return $user->role === 'admin';
    }

    /**
     * Admin dapat mengubah siapa pun; pengguna lain hanya profilnya sendiri.
     * Perubahan role/is_active oleh non-admin dicegah di controller.
     */
    public function update(User $user, User $target): bool
    {
        return $user->role === 'admin' || (int) $user->id === (int) $target->id;
    }

    /**
     * Penghapusan akun hanya oleh admin.
     */
    public function delete(User $user, User $target): bool
    {
        return $user->role === 'admin';
    }
}
