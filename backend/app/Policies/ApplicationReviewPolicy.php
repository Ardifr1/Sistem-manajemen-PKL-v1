<?php

namespace App\Policies;

use App\Models\ApplicationReview;
use App\Models\User;
use App\Policies\Concerns\ResolvesCompanyAccess;

class ApplicationReviewPolicy
{
    use ResolvesCompanyAccess;

    public function viewAny(User $user): bool
    {
        return in_array($user->role, ['admin', 'teacher', 'student', 'company'], true);
    }

    public function view(User $user, ApplicationReview $applicationReview): bool
    {
        if (in_array($user->role, ['admin', 'teacher'], true)) {
            return true;
        }

        if ($user->role === 'student') {
            $application = $applicationReview->application;

            return $application && $application->student_id === $user->id;
        }

        if ($user->role === 'company') {
            $application = $applicationReview->application;

            return $application
                && $this->isAuthorizedForCompany($user, $application->company_id);
        }

        return false;
    }

    public function create(User $user): bool
    {
        return in_array($user->role, ['company', 'admin'], true);
    }

    /**
     * Pembuatan review atas lamaran ke perusahaan tertentu.
     *
     * Hard Block konflik kepentingan: Admin yang menjadi anggota AKTIF
     * perusahaan tujuan tidak boleh mengambil keputusan (approve/reject) atas
     * nama perusahaan tersebut. Perusahaan sendiri dan admin lain tetap boleh.
     */
    public function createForApplication(User $user, int $companyId): bool
    {
        if (! $this->create($user)) {
            return false;
        }

        if ($user->role === 'admin') {
            return ! $this->hasConflictOfInterest($user, $companyId);
        }

        return true;
    }

    public function update(User $user, ApplicationReview $applicationReview): bool
    {
        if ($user->role === 'admin') {
            return ! $this->hasConflictOfInterest(
                $user,
                $applicationReview->application?->company_id
            );
        }

        // Hanya reviewer aslinya yang boleh mengubah review.
        return $user->role === 'company'
            && $applicationReview->reviewer_id === $user->id;
    }

    public function delete(User $user, ApplicationReview $applicationReview): bool
    {
        if ($user->role === 'admin') {
            return true;
        }

        return $user->role === 'company'
            && $applicationReview->reviewer_id === $user->id;
    }
}
