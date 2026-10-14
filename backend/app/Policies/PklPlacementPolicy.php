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
        if (in_array($user->role, ['admin', 'teacher'], true)) {
            return true;
        }

        if ($user->role === 'student') {
            return $placement->student_id === $user->id;
        }

        if ($user->role === 'company') {
            return $this->isAuthorizedForCompany($user, $placement->company_id);
        }

        if ($user->role === 'supervisor') {
            $supervisor = $user->companySupervisor;

            if (! $supervisor) {
                return false;
            }

            // Pembimbing industri hanya boleh mengakses siswa yang
            // ditugaskan kepadanya; bila placement belum menetapkan
            // supervisor spesifik, batasi lingkup perusahaan sebagai fallback.
            if ($placement->supervisor_id !== null) {
                return (int) $placement->supervisor_id === (int) $supervisor->id;
            }

            return (int) $placement->company_id === (int) $supervisor->company_id;
        }

        return false;
    }

    public function create(User $user): bool
    {
        return $user->role === 'admin';
    }

    public function update(User $user, PklPlacement $placement): bool
    {
        return $user->role === 'admin';
    }

    public function delete(User $user, PklPlacement $placement): bool
    {
        return $user->role === 'admin';
    }
}
