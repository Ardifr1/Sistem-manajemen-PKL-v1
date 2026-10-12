<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FinalAssessmentStatusLog extends Model
{
    protected $fillable = [
        'final_assessment_id',
        'action',
        'changed_by',
        'note',
    ];

    public function finalAssessment(): BelongsTo
    {
        return $this->belongsTo(FinalAssessment::class);
    }

    public function changer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
