<?php

namespace App\Policies;

use App\Models\AssessmentComponent;
use App\Models\User;

class AssessmentComponentPolicy
{
    public function viewAny(User $user): bool
    {
        // Supervisor (Pembimbing Industri) butuh baca komponen untuk menilai;
        // komponen adalah master global, read-only aman untuk semua role.
        return in_array($user->role, ['admin', 'teacher', 'student', 'company', 'supervisor'], true);
    }

    public function view(User $user, AssessmentComponent $assessmentComponent): bool
    {
        return in_array($user->role, ['admin', 'teacher', 'student', 'company', 'supervisor'], true);
    }

    public function create(User $user): bool
    {
        return $user->role === 'admin';
    }

    public function update(User $user, AssessmentComponent $assessmentComponent): bool
    {
        return $user->role === 'admin';
    }

    public function delete(User $user, AssessmentComponent $assessmentComponent): bool
    {
        return $user->role === 'admin';
    }
}
