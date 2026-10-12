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
            return $user->companySupervisor
                && $placement->supervisor_id === $user->companySupervisor->id;
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
