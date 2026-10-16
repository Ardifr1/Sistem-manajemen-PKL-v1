<?php

namespace App\Policies;

use App\Models\CompanyMembership;
use App\Models\User;

class CompanyMembershipPolicy
{
    /**
     * Daftar keanggotaan: admin melihat semua, user lain hanya miliknya
     * (scoping dilakukan di controller).
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * Detail keanggotaan: admin atau pemilik keanggotaan.
     */
    public function view(User $user, CompanyMembership $membership): bool
    {
        return $user->role === 'admin'
            || (int) $membership->user_id === (int) $user->id;
    }

    /**
     * Pemberian akses keanggotaan perusahaan hanya oleh Admin.
     */
    public function create(User $user): bool
    {
        return $user->role === 'admin';
    }

    /**
     * Perubahan status/kapabilitas keanggotaan hanya oleh Admin.
     */
    public function update(User $user, CompanyMembership $membership): bool
    {
        return $user->role === 'admin';
    }

    /**
     * Pencabutan akses hanya oleh Admin.
     */
    public function delete(User $user, CompanyMembership $membership): bool
    {
        return $user->role === 'admin';
    }
}
