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
        if (in_array($user->role, ['admin', 'teacher'], true)) {
            return true;
        }

        // Siswa hanya boleh melihat nilai akhir miliknya yang sudah dipublikasikan.
        if ($user->role === 'student') {
            return $finalAssessment->status === 'published'
                && $finalAssessment->placement
                && $finalAssessment->placement->student_id === $user->id;
        }

        if ($user->role === 'company') {
            return $finalAssessment->placement
                && $this->isAuthorizedForCompany($user, $finalAssessment->placement->company_id);
        }

        return false;
    }

    public function create(User $user): bool
    {
        return in_array($user->role, ['teacher', 'admin'], true);
    }

    public function update(User $user, FinalAssessment $finalAssessment): bool
    {
        return in_array($user->role, ['teacher', 'admin'], true);
    }

    public function delete(User $user, FinalAssessment $finalAssessment): bool
    {
        return in_array($user->role, ['teacher', 'admin'], true);
    }

    /**
     * Publikasi dan pembatalan publikasi hanya boleh dilakukan Admin.
     */
    public function publish(User $user): bool
    {
        return $user->role === 'admin';
    }

    public function unpublish(User $user): bool
    {
        return $user->role === 'admin';
    }
}
