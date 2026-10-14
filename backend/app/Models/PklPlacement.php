<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class PklPlacement extends Model
{
    protected $fillable = [
        'student_id',
        'company_id',
        'teacher_id',
        'supervisor_id',
        'pkl_period_id',
        'application_id',
        'start_date',
        'end_date',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
        ];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_id');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'teacher_id');
    }

    public function supervisor(): BelongsTo
    {
        return $this->belongsTo(CompanySupervisor::class, 'supervisor_id');
    }

    public function pklPeriod(): BelongsTo
    {
        return $this->belongsTo(PklPeriod::class);
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(PklApplication::class, 'application_id');
    }

    public function finalAssessment(): HasOne
    {
        return $this->hasOne(FinalAssessment::class, 'placement_id');
    }
}