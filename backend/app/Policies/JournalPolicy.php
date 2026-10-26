<?php

namespace App\Policies;

use App\Models\Journal;
use App\Models\User;
use App\Policies\Concerns\ResolvesCompanyAccess;

class JournalPolicy
{
    use ResolvesCompanyAccess;

    public function viewAny(User $user): bool
    {
        if (in_array($user->role, [
            'admin',
            'teacher',
            'student',
        ], true)) {
            return true;
        }

        if (in_array($user->role, ['company', 'supervisor'], true)) {
            return $this->authorizedCompanyIds($user) !== [];
        }

        return false;
    }

    public function view(User $user, Journal $journal): bool
    {
        if ($user->role === 'admin') {
            return true;
        }

        // Guru Pembimbing hanya boleh membaca jurnal siswa bimbingannya
        // (pkl_placements.teacher_id), konsisten dengan scoping index.
        if ($user->role === 'teacher') {
            return $journal->placement
                && $journal->placement->teacher_id !== null
                && (int) $journal->placement->teacher_id === (int) $user->id;
        }

        if ($user->role === 'company') {
            return $journal->placement
                && $this->isAuthorizedForCompany($user, $journal->placement->company_id);
        }

        if ($user->role === 'supervisor') {
            return $journal->placement
                && $user->companySupervisor
                && $journal->placement->supervisor_id === $user->companySupervisor->id;
        }

        return $user->role === 'student'
            && $journal->placement
            && $journal->placement->student_id === $user->id;
    }

    /**
     * Siswa dapat membuat jurnal.
     */
    public function create(User $user): bool
    {
        return $user->role === 'student';
    }

    /**
     * Siswa hanya dapat mengubah jurnal miliknya.
     * Admin/Guru dapat mengelola jurnal.
     */
    public function update(User $user, Journal $journal): bool
    {
        if (in_array($user->role, [
            'admin',
            'teacher',
        ], true)) {
            return true;
        }

        return $user->role === 'student'
            && $journal->placement
            && $journal->placement->student_id === $user->id;
    }

    /**
     * Siswa hanya dapat menghapus jurnal miliknya.
     * Admin dapat menghapus jurnal.
     */
    public function delete(User $user, Journal $journal): bool
    {
        if ($user->role === 'admin') {
            return true;
        }

        return $user->role === 'student'
            && $journal->placement
            && $journal->placement->student_id === $user->id;
    }

    /**
     * Restore tidak digunakan untuk Journal saat ini.
     */
    public function restore(User $user, Journal $journal): bool
    {
        return false;
    }

    /**
     * Force delete tidak digunakan untuk Journal saat ini.
     */
    public function forceDelete(User $user, Journal $journal): bool
    {
        return false;
    }
}