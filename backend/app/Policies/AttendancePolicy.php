<?php

namespace App\Policies;

use App\Models\Attendance;
use App\Models\User;
use App\Policies\Concerns\ResolvesCompanyAccess;

class AttendancePolicy
{
    use ResolvesCompanyAccess;

    public function viewAny(User $user): bool
    {
        return in_array($user->role, [
            'admin',
            'teacher',
            'student',
            'company',
            'supervisor',
        ], true);
    }

    public function view(User $user, Attendance $attendance): bool
    {
        if (in_array($user->role, ['admin', 'teacher'], true)) {
            return true;
        }

        if ($user->role === 'student') {
            return $attendance->placement
                && $attendance->placement->student_id === $user->id;
        }

        if ($user->role === 'company') {
            return $attendance->placement
                && $this->isAuthorizedForCompany($user, $attendance->placement->company_id);
        }

        // BR-09a: pembimbing industri hanya boleh membuka absensi siswa yang
        // ditugaskan kepadanya — konsisten dengan scoping daftar absensi.
        if ($user->role === 'supervisor') {
            $supervisor = $user->companySupervisor;

            return $supervisor !== null
                && $attendance->placement !== null
                && $attendance->placement->supervisor_id !== null
                && (int) $attendance->placement->supervisor_id === (int) $supervisor->id;
        }

        return false;
    }

    public function create(User $user): bool
    {
        return in_array($user->role, ['student', 'company'], true);
    }

    public function update(User $user, Attendance $attendance): bool
    {
        if ($user->role === 'admin') {
            return true;
        }

        if ($user->role === 'student') {
            return $attendance->placement
                && $attendance->placement->student_id === $user->id;
        }

        if ($user->role === 'company') {
            return $attendance->placement
                && $this->isAuthorizedForCompany($user, $attendance->placement->company_id);
        }

        return false;
    }

    public function delete(User $user, Attendance $attendance): bool
    {
        if ($user->role === 'admin') {
            return true;
        }

        if ($user->role === 'student') {
            return $attendance->placement
                && $attendance->placement->student_id === $user->id;
        }

        if ($user->role === 'company') {
            return $attendance->placement
                && $this->isAuthorizedForCompany($user, $attendance->placement->company_id);
        }

        return false;
    }
}
