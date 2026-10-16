<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Keanggotaan perusahaan yang eksplisit.
 *
 * Menyimpan hubungan user <-> perusahaan beserta kapabilitasnya. Kapabilitas
 * diberikan/dicabut oleh pihak berwenang (Admin) dan setiap perubahan dicatat
 * pada tabel audit_logs.
 */
class CompanyMembership extends Model
{
    /** Kapabilitas yang dikenal sistem. */
    public const CAPABILITY_COMPANY_ADMIN = 'company_admin';

    public const CAPABILITY_REVIEW_APPLICATIONS = 'review_applications';

    public const CAPABILITY_VERIFY_JOURNALS = 'verify_journals';

    public const CAPABILITIES = [
        self::CAPABILITY_COMPANY_ADMIN,
        self::CAPABILITY_REVIEW_APPLICATIONS,
        self::CAPABILITY_VERIFY_JOURNALS,
    ];

    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

    protected $fillable = [
        'user_id',
        'company_id',
        'status',
        'capabilities',
        'granted_by',
        'granted_at',
        'revoked_at',
    ];

    protected function casts(): array
    {
        return [
            'capabilities' => 'array',
            'granted_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function grantedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'granted_by');
    }

    public function scopeActive($query)
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    /**
     * Cek kapabilitas. Keanggotaan nonaktif tidak punya kapabilitas apa pun.
     */
    public function hasCapability(string $capability): bool
    {
        if (! $this->isActive()) {
            return false;
        }

        return in_array($capability, $this->capabilities ?? [], true);
    }
}
