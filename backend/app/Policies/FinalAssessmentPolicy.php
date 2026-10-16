<?php

namespace App\Policies;

use App\Models\FinalAssessment;
use App\Models\User;
use App\Policies\Concerns\ResolvesCompanyAccess;

class FinalAssessmentPolicy
{
    use ResolvesCompanyAccess;

    public function viewAny(User $user): bool
    {
        return in_array($user->role, ['admin', 'teacher', 'student'], true);
    }

    public function view(User $user, FinalAssessment $finalAssessment): bool
    {
        if ($user->role === 'admin') {
            return true;
        }

        $placement = $finalAssessment->placement;

        // Guru Pembimbing hanya untuk siswa bimbingannya (teacher_id).
        if ($user->role === 'teacher') {
            return $placement !== null
                && $placement->teacher_id !== null
                && (int) $placement->teacher_id === (int) $user->id;
        }

        // Siswa hanya boleh melihat nilai akhir miliknya yang sudah dipublikasikan.
        if ($user->role === 'student') {
            return $finalAssessment->status === 'published'
                && $placement !== null
                && (int) $placement->student_id === (int) $user->id;
        }

        if ($user->role === 'company') {
            return $placement !== null
                && $this->isAuthorizedForCompany($user, $placement->company_id);
        }

        return false;
    }

    public function create(User $user): bool
    {
        return in_array($user->role, ['teacher', 'admin'], true);
    }

    public function update(User $user, FinalAssessment $finalAssessment): bool
    {
        if ($user->role === 'admin') {
            return true;
        }

        return $user->role === 'teacher' && $this->teaches($user, $finalAssessment);
    }

    public function delete(User $user, FinalAssessment $finalAssessment): bool
    {
        if ($user->role === 'admin') {
            return true;
        }

        return $user->role === 'teacher' && $this->teaches($user, $finalAssessment);
    }

    /**
     * Apakah guru ini pembimbing resmi (placement.teacher_id) atas nilai tsb.
     */
    private function teaches(User $user, FinalAssessment $finalAssessment): bool
    {
        $placement = $finalAssessment->placement;

        return $placement !== null
            && $placement->teacher_id !== null
            && (int) $placement->teacher_id === (int) $user->id;
    }

    /**
     * Publikasi dan pembatalan publikasi hanya boleh dilakukan Admin.
     *
     * Hard Block konflik kepentingan: Admin yang memiliki keanggotaan aktif
     * pada perusahaan penempatan tidak boleh menetapkan (memublikasikan)
     * nilai siswa perusahaan tersebut dari sisi sekolah.
     */
    public function publish(User $user, FinalAssessment $finalAssessment): bool
    {
        return $user->role === 'admin'
            && ! $this->hasConflictOfInterest($user, $finalAssessment->placement?->company_id);
    }

    public function unpublish(User $user, FinalAssessment $finalAssessment): bool
    {
        return $user->role === 'admin'
            && ! $this->hasConflictOfInterest($user, $finalAssessment->placement?->company_id);
    }
}
