<?php

namespace App\Policies;

use App\Models\Assessment;
use App\Models\User;
use App\Policies\Concerns\ResolvesCompanyAccess;

class AssessmentPolicy
{
    use ResolvesCompanyAccess;

    public function viewAny(User $user): bool
    {
        return in_array($user->role, ['admin', 'teacher', 'student', 'supervisor', 'company'], true);
    }

    public function view(User $user, Assessment $assessment): bool
    {
        if (in_array($user->role, ['admin', 'teacher'], true)) {
            return true;
        }

        if ($user->role === 'student') {
            return $assessment->placement
                && $assessment->placement->student_id === $user->id;
        }

        if ($user->role === 'supervisor') {
            return $assessment->placement
                && $user->companySupervisor
                && $assessment->placement->supervisor_id === $user->companySupervisor->id;
        }

        if ($user->role === 'company') {
            return $assessment->placement
                && $this->isAuthorizedForCompany($user, $assessment->placement->company_id);
        }

        return false;
    }

    public function create(User $user): bool
    {
        return in_array($user->role, ['teacher', 'supervisor'], true);
    }

    public function update(User $user, Assessment $assessment): bool
    {
        if ($user->role === 'admin') {
            return true;
        }

        // Hanya penilai aslinya yang boleh mengubah penilaian.
        if (in_array($user->role, ['teacher', 'supervisor'], true)) {
            return $assessment->assessed_by === $user->id;
        }

        return false;
    }

    public function delete(User $user, Assessment $assessment): bool
    {
        if ($user->role === 'admin') {
            return true;
        }

        if (in_array($user->role, ['teacher', 'supervisor'], true)) {
            return $assessment->assessed_by === $user->id;
        }

        return false;
    }
}
