<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FinalAssessment extends Model
{
    protected $fillable = [
        'placement_id',
        'final_score',
        'status',
        'finalized_by',
        'finalized_at',
        'published_by',
        'published_at',
        'unpublish_reason',
    ];

    protected function casts(): array
    {
        return [
            'final_score' => 'decimal:2',
            'finalized_at' => 'datetime',
            'published_at' => 'datetime',
        ];
    }

    public function placement(): BelongsTo
    {
        return $this->belongsTo(PklPlacement::class, 'placement_id');
    }

    public function finalizer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'finalized_by');
    }

    public function publisher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by');
    }
}