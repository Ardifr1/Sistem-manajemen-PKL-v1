<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Company extends Model
{
    /** Status verifikasi alur perusahaan nonmitra (PRD: pengajuan siswa). */
    public const VERIFICATION_PENDING = 'pending_school_verification';

    public const VERIFICATION_VERIFIED = 'verified';

    public const VERIFICATION_REJECTED = 'rejected';

    protected $fillable = [
        'name',
        'industry',
        'description',
        'address',
        'phone',
        'email',
        'contact_person',
        'student_quota',
        'required_skills',
        'required_documents',
        'is_partner',
        'is_active',
        'verification_status',
        'submitted_by',
        'proof_path',
        'verification_note',
        'verified_by',
        'verified_at',
    ];

    protected function casts(): array
    {
        return [
            'student_quota' => 'integer',
            'is_partner' => 'boolean',
            'is_active' => 'boolean',
            'verified_at' => 'datetime',
        ];
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(CompanyMembership::class);
    }

    /** Siswa yang mengajukan perusahaan nonmitra ini. */
    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    /** Admin yang memverifikasi pengajuan perusahaan ini. */
    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    public function activeMemberships(): HasMany
    {
        return $this->hasMany(CompanyMembership::class)->where('status', CompanyMembership::STATUS_ACTIVE);
    }

    /**
     * Perusahaan yang pengajuan nonmitranya masih menunggu verifikasi sekolah
     * tidak boleh dipakai untuk pengajuan maupun penempatan resmi.
     */
    public function isPendingSchoolVerification(): bool
    {
        return $this->verification_status === self::VERIFICATION_PENDING;
    }

    public function isVerificationRejected(): bool
    {
        return $this->verification_status === self::VERIFICATION_REJECTED;
    }

    public function isVerified(): bool
    {
        return $this->verification_status === self::VERIFICATION_VERIFIED;
    }

    /**
     * Boleh dipakai pada alur pengajuan/penempatan resmi?
     * Data lama (verification_status NULL) tetap dianggap sah selama
     * is_partner & is_active bernilai benar.
     */
    public function isUsableForPlacement(): bool
    {
        // Bisa dipakai penempatan bila: mitra resmi ATAU nonmitra yang sudah
        // terverifikasi sekolah — dan aktif serta tidak pending/ditolak.
        return ((bool) $this->is_partner || $this->isVerified())
            && (bool) $this->is_active
            && ! $this->isPendingSchoolVerification()
            && ! $this->isVerificationRejected();
    }
}