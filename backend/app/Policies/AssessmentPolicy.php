<?php

namespace App\Policies;

use App\Models\Assessment;
use App\Models\PklPlacement;
use App\Models\User;
use App\Policies\Concerns\ResolvesCompanyAccess;

class AssessmentPolicy
{
    use ResolvesCompanyAccess;

    public function viewAny(User $user): bool
    {
        // Company and supervisors may review results; index is scoped to
        // placements they are allowed to access.
        return in_array($user->role, ['admin', 'teacher', 'student', 'company', 'supervisor'], true);
    }

    public function view(User $user, Assessment $assessment): bool
    {
        if ($user->role === 'admin') {
            return true;
        }

        $placement = $assessment->placement;

        // Guru Pembimbing hanya boleh melihat penilaian siswa bimbingannya.
        if ($user->role === 'teacher') {
            return $placement !== null
                && $placement->teacher_id !== null
                && (int) $placement->teacher_id === (int) $user->id;
        }

        if ($user->role === 'student') {
            return $placement !== null
                && (int) $placement->student_id === (int) $user->id;
        }

        if ($user->role === 'company') {
            return $placement !== null
                && $this->isAuthorizedForCompany($user, $placement->company_id);
        }

        if ($user->role === 'supervisor') {
            $supervisor = $user->companySupervisor;

            if (! $supervisor || ! $placement) {
                return false;
            }

            // BR-09a: hanya siswa yang ditugaskan ke pembimbing ini.
            return $placement->supervisor_id !== null
                && (int) $placement->supervisor_id === (int) $supervisor->id;
        }

        return false;
    }

    public function create(User $user): bool
    {
        return in_array($user->role, ['teacher', 'supervisor'], true);
    }

    /**
     * Hard Block konflik kepentingan (sisi sekolah).
     *
     * Guru Pembimbing yang memiliki keanggotaan aktif pada perusahaan
     * penempatan tidak boleh mengisi penilaian sisi sekolah untuk siswa dari
     * perusahaan tersebut. Jalur Pembimbing Industri (role company/supervisor)
     * tidak dibatasi di sini karena tindakan tersebut sah.
     */
    public function createForPlacement(User $user, PklPlacement $placement): bool
    {
        if ($user->role !== 'teacher') {
            return in_array($user->role, ['company', 'supervisor'], true);
        }

        return ! $this->hasConflictOfInterest($user, $placement->company_id);
    }

    public function update(User $user, Assessment $assessment): bool
    {
        if ($user->role === 'admin') {
            return true;
        }

        // Hanya penilai aslinya yang boleh mengubah penilaian, dan untuk guru
        // harus tetap siswa bimbingannya.
        if ($user->role === 'teacher') {
            $placement = $assessment->placement;

            return $assessment->assessed_by === $user->id
                && $placement !== null
                && (int) $placement->teacher_id === (int) $user->id
                && ! $this->hasConflictOfInterest($user, $placement->company_id);
        }

        if ($user->role === 'supervisor') {
            return $assessment->assessed_by === $user->id;
        }

        return false;
    }

    public function delete(User $user, Assessment $assessment): bool
    {
        if ($user->role === 'admin') {
            return true;
        }

        if ($user->role === 'teacher') {
            $placement = $assessment->placement;

            return $assessment->assessed_by === $user->id
                && $placement !== null
                && (int) $placement->teacher_id === (int) $user->id;
        }

        if ($user->role === 'supervisor') {
            return $assessment->assessed_by === $user->id;
        }

        return false;
    }
}
