<?php

namespace App\Policies;

use App\Models\PklApplication;
use App\Models\User;
use App\Policies\Concerns\ResolvesCompanyAccess;

class PklApplicationPolicy
{
    use ResolvesCompanyAccess;

    public function viewAny(User $user): bool
    {
        return in_array($user->role, [
            'admin',
            'teacher',
            'student',
        ], true);
    }

    public function view(User $user, PklApplication $application): bool
    {
        if (in_array($user->role, [
            'admin',
            'teacher',
        ], true)) {
            return true;
        }

        if ($user->role === 'student') {
            return $application->student_id === $user->id;
        }

        // Company melihat lamaran yang ditujukan ke perusahaannya
        // (diperlukan untuk proses review lamaran).
        if ($user->role === 'company') {
            return $this->isAuthorizedForCompany($user, $application->company_id);
        }

        return false;
    }

    public function create(User $user): bool
    {
        return $user->role === 'student';
    }

    /**
     * Penentuan pilihan final oleh siswa sendiri (memilih satu dari beberapa
     * tawaran yang diterima).
     */
    public function choose(User $user, PklApplication $application): bool
    {
        return $user->role === 'student'
            && (int) $application->student_id === (int) $user->id;
    }

    /**
     * Review perusahaan atas lamaran yang ditujukan ke perusahaannya
     * (transisi pending/seleksi/interview -> seleksi/interview/accepted/rejected).
     *
     * User harus berwenang atas perusahaan tujuan lamaran (keanggotaan aktif
     * atau tautan perusahaan). Keputusan perusahaan BUKAN tindakan sisi
     * sekolah, sehingga Hard Block konflik kepentingan tidak berlaku di sini.
     */
    public function review(User $user, PklApplication $application): bool
    {
        return $user->role === 'company'
            && $this->isAuthorizedForCompany($user, $application->company_id);
    }

    /**
     * Persetujuan sekolah atas lamaran (accepted -> approved/rejected).
     *
     * Hard Block konflik kepentingan: Admin yang memiliki keanggotaan aktif
     * pada perusahaan tujuan lamaran tidak boleh menyetujui/menolak lamaran
     * tersebut dari sisi sekolah.
     */
    public function approve(User $user, PklApplication $application): bool
    {
        return $user->role === 'admin'
            && ! $this->hasConflictOfInterest($user, $application->company_id);
    }

    public function update(User $user, PklApplication $application): bool
    {
        if ($user->role === 'admin') {
            return true;
        }

        return $user->role === 'student'
            && $application->student_id === $user->id;
    }

    public function delete(User $user, PklApplication $application): bool
    {
        if ($user->role === 'admin') {
            return true;
        }

        return $user->role === 'student'
            && $application->student_id === $user->id;
    }

    public function restore(User $user, PklApplication $application): bool
    {
        return false;
    }

    public function forceDelete(User $user, PklApplication $application): bool
    {
        return false;
    }
}