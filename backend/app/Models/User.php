<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable([
    'name',
    'username',
    'email',
    'phone',
    'nip',
    'password',
    'password_setup_token',
    'password_setup_expires_at',
    'role',
    'school_id',
    'is_active',
    'must_change_password',
    'activated_at',
])]
#[Hidden([
    'password',
    'remember_token',
    'password_setup_token',
    'password_setup_expires_at',
])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'password_setup_expires_at' => 'datetime',
        ];
    }

    public function studentProfile(): HasOne
    {
        return $this->hasOne(StudentProfile::class);
    }
    
    public function teacherProfile(): HasOne
{
    return $this->hasOne(TeacherProfile::class);
}

    public function companySupervisor(): HasOne
    {
        return $this->hasOne(CompanySupervisor::class);
    }

    /**
     * Penempatan PKL milik siswa ini (kolom student_id pada pkl_placements).
     * Dipakai untuk membatasi daftar pengguna: guru hanya melihat siswanya,
     * perusahaan/pembimbing hanya melihat siswa di perusahaannya.
     */
    public function placements(): HasMany
    {
        return $this->hasMany(PklPlacement::class, 'student_id');
    }

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    /**
     * Keanggotaan perusahaan eksplisit (beserta kapabilitas & status).
     */
    public function companyMemberships(): HasMany
    {
        return $this->hasMany(CompanyMembership::class);
    }

    /**
     * Keanggotaan perusahaan yang masih aktif saja.
     */
    public function activeCompanyMemberships(): HasMany
    {
        return $this->hasMany(CompanyMembership::class)
            ->where('status', CompanyMembership::STATUS_ACTIVE);
    }
}