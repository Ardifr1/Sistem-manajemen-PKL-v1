<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AssessmentComponent extends Model
{
    protected $fillable = [
        'name',
        'description',
        'owner_type',
        'weight',
        'assessor_role',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'weight' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    /**
     * `owner_type` (industri|guru) dan `assessor_role` (supervisor|teacher)
     * menyatakan hal yang sama. Selaraskan otomatis agar kedua sisi (UI lama
     * yang memakai owner_type dan UI baru yang memakai assessor_role) selalu
     * konsisten — termasuk untuk data yang di-seed atau dibuat lewat tinker.
     */
    protected static function booted(): void
    {
        static::saving(function (AssessmentComponent $component): void {
            if ($component->isDirty('owner_type')) {
                $component->assessor_role = $component->owner_type === 'industri'
                    ? 'supervisor'
                    : 'teacher';
            } elseif ($component->isDirty('assessor_role')) {
                $component->owner_type = $component->assessor_role === 'supervisor'
                    ? 'industri'
                    : 'guru';
            }
        });
    }
}
