<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuditLog extends Model
{
    protected $fillable = [
        'user_id',
        'action',
        'placement_id',
        'final_assessment_id',
        'old_status',
        'new_status',
        'reason',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function placement(): BelongsTo
    {
        return $this->belongsTo(PklPlacement::class, 'placement_id');
    }

    public function finalAssessment(): BelongsTo
    {
        return $this->belongsTo(FinalAssessment::class, 'final_assessment_id');
    }
}
