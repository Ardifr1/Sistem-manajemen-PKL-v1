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

        if ($user->role === 'student') {
            // Siswa hanya melihat nilai akhir yang sudah dipublikasikan.
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

    /** Hanya admin yang boleh memublikasikan nilai akhir. */
    public function publish(User $user): bool
    {
        return $user->role === 'admin';
    }

    /** Hanya admin yang boleh membatalkan publikasi nilai akhir. */
    public function unpublish(User $user, FinalAssessment $finalAssessment): bool
    {
        return $user->role === 'admin';
    }
}
