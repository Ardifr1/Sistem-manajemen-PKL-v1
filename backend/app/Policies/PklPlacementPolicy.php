<?php

namespace App\Policies;

use App\Models\PklPlacement;
use App\Models\User;
use App\Policies\Concerns\ResolvesCompanyAccess;

class PklPlacementPolicy
{
    use ResolvesCompanyAccess;

    public function viewAny(User $user): bool
    {
        return in_array($user->role, ['admin', 'teacher', 'student', 'company', 'supervisor'], true);
    }

    public function view(User $user, PklPlacement $placement): bool
    {
        if ($user->role === 'admin') {
            return true;
        }

        // Guru Pembimbing hanya boleh mengakses siswa yang ditugaskan
        // kepadanya (placement.teacher_id), konsisten dengan scoping index.
        if ($user->role === 'teacher') {
            return $placement->teacher_id !== null
                && (int) $placement->teacher_id === (int) $user->id;
        }

        if ($user->role === 'student') {
            return (int) $placement->student_id === (int) $user->id;
        }

        if ($user->role === 'company') {
            return $this->isAuthorizedForCompany($user, $placement->company_id);
        }

        if ($user->role === 'supervisor') {
            $supervisor = $user->companySupervisor;

            if (! $supervisor) {
                return false;
            }

            // BR-09a: Pembimbing Industri hanya boleh mengakses siswa yang
            // secara spesifik ditugaskan kepadanya (placement.supervisor_id).
            return $placement->supervisor_id !== null
                && (int) $placement->supervisor_id === (int) $supervisor->id;
        }

        return false;
    }

    public function create(User $user): bool
    {
        return $user->role === 'admin';
    }

    /**
     * Hard Block konflik kepentingan: Admin yang memiliki keanggotaan aktif
     * pada perusahaan tujuan tidak boleh membuat penempatan (persetujuan
     * penempatan resmi) untuk perusahaan tersebut.
     */
    public function createForCompany(User $user, int $companyId): bool
    {
        return $user->role === 'admin'
            && ! $this->hasConflictOfInterest($user, $companyId);
    }

    /**
     * Update penempatan: admin, kecuali ia memiliki keanggotaan aktif pada
     * perusahaan penempatan tersebut (baik perusahaan lama maupun baru).
     */
    public function update(User $user, PklPlacement $placement): bool
    {
        return $user->role === 'admin'
            && ! $this->hasConflictOfInterest($user, $placement->company_id);
    }

    public function delete(User $user, PklPlacement $placement): bool
    {
        return $user->role === 'admin';
    }
}
